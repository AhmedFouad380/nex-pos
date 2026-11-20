<?php

namespace App\Domain\Finance\Services;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\ContactOpeningBalance;
use App\Domain\CRM\Models\LedgerDiscount;
use App\Domain\Finance\Data\JournalDraft;
use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\AccountTransfer;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * What journal entry a document should have *right now*, computed from the document alone.
 * Returns null when it should have none (drafts, pending states, voided documents).
 *
 * Control accounts: customers' balances live in Receivable, suppliers' in Payable, each line tagged
 * with the contact — so Σ contacts.balance = Receivable − Payable. Inventory is posted at the value of
 * the stock movements themselves, so the Inventory account always equals the stock valuation.
 */
final class PostingRules
{
    /** Documents that post, in the order `ledger:rebuild` replays them. */
    public const DOCUMENTS = [
        ContactOpeningBalance::class, StockAdjustment::class, Purchase::class, PurchaseReturn::class,
        Sale::class, SaleReturn::class, Expense::class, Payment::class, LedgerDiscount::class,
        AccountTransfer::class, CashSession::class, PayrollRun::class,
    ];

    public function __construct(private readonly SystemAccounts $accounts) {}

    public static function supports(Model $document): bool
    {
        return in_array($document::class, self::DOCUMENTS, true);
    }

    public static function voidedAt(Model $document): mixed
    {
        return array_key_exists('voided_at', $document->getAttributes()) ? $document->getAttribute('voided_at') : null;
    }

    public function draft(Model $document): ?JournalDraft
    {
        if (self::voidedAt($document) !== null) {
            return null;
        }

        return match (true) {
            $document instanceof Sale => $this->sale($document),
            $document instanceof SaleReturn => $this->saleReturn($document),
            $document instanceof Purchase => $this->purchase($document),
            $document instanceof PurchaseReturn => $this->purchaseReturn($document),
            $document instanceof Payment => $this->payment($document),
            $document instanceof Expense => $this->expense($document),
            $document instanceof StockAdjustment => $this->stockAdjustment($document),
            $document instanceof ContactOpeningBalance => $this->openingBalance($document),
            $document instanceof LedgerDiscount => $this->ledgerDiscount($document),
            $document instanceof AccountTransfer => $this->transfer($document),
            $document instanceof CashSession => $this->cashSession($document),
            $document instanceof PayrollRun => $this->payroll($document),
            default => null,
        };
    }

    private function sale(Sale $sale): ?JournalDraft
    {
        if ($sale->status !== SaleStatus::Final) {
            return null;
        }
        $draft = new JournalDraft($sale->sale_date, __('finance.posting.sale', ['number' => $sale->invoice_no]), $sale->location_id);
        $extras = Decimal::of($sale->tax_amount)->plus(Decimal::of($sale->shipping_charges))->plus(Decimal::of($sale->packing_charge))->plus(Decimal::of($sale->round_off));
        $revenue = Decimal::of($sale->total)->minus($extras)->plus(Decimal::of($sale->discount_amount));

        $draft->debit($this->accounts->id(Role::Receivable), $sale->total, $sale->customer_id)
            ->debit($this->accounts->id(Role::SalesDiscount), $sale->discount_amount)
            ->credit($this->accounts->id(Role::Sales), $revenue)
            ->credit($this->accounts->id(Role::TaxOutput), $sale->tax_amount)
            ->credit($this->accounts->id(Role::ShippingIncome), Decimal::of($sale->shipping_charges)->plus(Decimal::of($sale->packing_charge)))
            ->credit($this->accounts->id(Role::Rounding), $sale->round_off);

        $cost = $this->stockValue($sale->items())->negated(); // goods out: negative movements
        $draft->debit($this->accounts->id(Role::Cogs), $cost)->credit($this->accounts->id(Role::Inventory), $cost);

        return $draft->balanceWith($this->accounts->id(Role::Rounding));
    }

    private function saleReturn(SaleReturn $return): JournalDraft
    {
        $draft = new JournalDraft($return->return_date, __('finance.posting.sale_return', ['number' => $return->number]), $return->location_id);
        $draft->debit($this->accounts->id(Role::SalesReturn), Decimal::of($return->total)->minus(Decimal::of($return->tax_amount)))
            ->debit($this->accounts->id(Role::TaxOutput), $return->tax_amount)
            ->credit($this->accounts->id(Role::Receivable), $return->total, $return->customer_id);

        $restocked = $this->stockValue($return->items()); // only restocked lines moved stock back in
        $draft->debit($this->accounts->id(Role::Inventory), $restocked)->credit($this->accounts->id(Role::Cogs), $restocked);

        return $draft;
    }

    /**
     * Supplier is owed from the day of the invoice. Until the goods arrive the cost sits in
     * "goods in transit"; once received it moves to Inventory at the landed cost of the stock that came in.
     */
    private function purchase(Purchase $purchase): JournalDraft
    {
        $rate = Decimal::of($purchase->exchange_rate);
        $total = Decimal::of($purchase->total)->multipliedBy($rate);
        $tax = Decimal::of($purchase->tax_amount)->multipliedBy($rate);
        $draft = new JournalDraft($purchase->purchase_date, __('finance.posting.purchase', ['number' => $purchase->number]), $purchase->location_id);

        $draft->credit($this->accounts->id(Role::Payable), $total, $purchase->supplier_id)
            ->debit($this->accounts->id(Role::TaxInput), $tax);

        if ($purchase->status !== PurchaseStatus::Received) {
            return $draft->balanceWith($this->accounts->id(Role::GoodsInTransit)); // Dr goods in transit
        }

        $draft->debit($this->accounts->id(Role::Inventory), $this->stockValue($purchase->items()));
        // services and other lines that never enter stock are an expense of the period
        $nonStock = $purchase->items()->whereHas('variation.product', fn ($q) => $q->where(fn ($q) => $q->where('track_stock', false)
            ->orWhereIn('type', [ProductType::Service, ProductType::Combo])))
            ->get(['landed_unit_cost', 'base_qty'])
            ->reduce(fn (BigDecimal $sum, $item) => $sum->plus(Decimal::of($item->landed_unit_cost)->multipliedBy(Decimal::of($item->base_qty))), BigDecimal::zero());
        $draft->debit($this->accounts->id(Role::OperatingExpense), $nonStock);

        return $draft->balanceWith($this->accounts->id(Role::Rounding));
    }

    private function purchaseReturn(PurchaseReturn $return): JournalDraft
    {
        $draft = new JournalDraft($return->return_date, __('finance.posting.purchase_return', ['number' => $return->number]), $return->location_id);
        $draft->debit($this->accounts->id(Role::Payable), $return->total, $return->supplier_id)
            ->credit($this->accounts->id(Role::TaxInput), $return->tax_amount)
            ->debit($this->accounts->id(Role::Inventory), $this->stockValue($return->items())); // negative → credit

        // returned at a price different from what the stock cost us
        return $draft->balanceWith($this->accounts->id(Role::InventoryAdjustment));
    }

    private function payment(Payment $payment): JournalDraft
    {
        $label = $payment->direction === PaymentDirection::In ? 'finance.posting.receipt' : 'finance.posting.payment';
        $draft = new JournalDraft($payment->paid_at, __($label, ['number' => $payment->number]), $payment->location_id);
        $amount = Decimal::of($payment->amount);
        $signed = $payment->direction === PaymentDirection::In ? $amount : $amount->negated();

        $draft->debit($payment->account_id, $signed);
        if ($payment->counter_account_id !== null) {
            $draft->credit($payment->counter_account_id, $signed, $payment->contact_id);
        } else {
            $contact = Contact::query()->findOrFail($payment->contact_id);
            $draft->credit($this->controlAccount($contact, $payment->direction), $signed, $contact->id);
        }

        return $draft;
    }

    private function expense(Expense $expense): JournalDraft
    {
        $draft = new JournalDraft($expense->expense_date, __('finance.posting.expense', ['number' => $expense->number]), $expense->location_id);
        $sign = $expense->is_refund ? -1 : 1;
        $account = $expense->expenseCategory->account_id ?? $this->accounts->id(Role::OperatingExpense);

        return $draft->debit($account, Decimal::of($expense->subtotal)->multipliedBy($sign))
            ->debit($this->accounts->id(Role::TaxInput), Decimal::of($expense->tax_amount)->multipliedBy($sign))
            ->credit($this->accounts->id(Role::AccruedExpenses), Decimal::of($expense->total)->multipliedBy($sign))
            ->balanceWith($this->accounts->id(Role::Rounding));
    }

    private function stockAdjustment(StockAdjustment $adjustment): JournalDraft
    {
        $draft = new JournalDraft($adjustment->adjusted_at, __('finance.posting.stock_adjustment', ['number' => $adjustment->number]), $adjustment->location_id);
        $value = $this->stockValue($adjustment->items()); // + stock in, − stock out
        $counter = $adjustment->type === StockAdjustmentType::Opening ? Role::OpeningBalance : Role::InventoryAdjustment;

        return $draft->debit($this->accounts->id(Role::Inventory), $value)->credit($this->accounts->id($counter), $value);
    }

    private function openingBalance(ContactOpeningBalance $balance): JournalDraft
    {
        $draft = new JournalDraft($balance->as_of_date, __('finance.posting.opening_balance'), $balance->location_id);
        $balance->loadMissing('contact');
        $receivable = $balance->direction === BalanceDirection::Receivable;
        $control = $this->accounts->id(match (true) {
            $balance->contact->type === ContactType::Employee => Role::EmployeeAccounts,
            $receivable => Role::Receivable,
            default => Role::Payable,
        });
        $signed = $receivable ? Decimal::of($balance->amount) : Decimal::of($balance->amount)->negated();

        return $draft->debit($control, $signed, $balance->contact_id)->credit($this->accounts->id(Role::OpeningBalance), $signed);
    }

    private function ledgerDiscount(LedgerDiscount $discount): JournalDraft
    {
        $draft = new JournalDraft($discount->date, __('finance.posting.ledger_discount', ['number' => $discount->number]), $discount->location_id);

        $employee = $discount->loadMissing('contact')->contact->type === ContactType::Employee;

        return $discount->direction === BalanceDirection::Receivable
            ? $draft->debit($this->accounts->id(Role::SalesDiscount), $discount->amount)
                ->credit($this->accounts->id($employee ? Role::EmployeeAccounts : Role::Receivable), $discount->amount, $discount->contact_id)
            : $draft->debit($this->accounts->id($employee ? Role::EmployeeAccounts : Role::Payable), $discount->amount, $discount->contact_id)
                ->credit($this->accounts->id(Role::PurchaseDiscount), $discount->amount);
    }

    private function transfer(AccountTransfer $transfer): JournalDraft
    {
        return (new JournalDraft($transfer->transferred_at, __('finance.posting.transfer', ['number' => $transfer->number])))
            ->debit($transfer->to_account_id, $transfer->amount)
            ->credit($transfer->from_account_id, $transfer->amount);
    }

    /** Only the counted − expected difference of a closed shift posts; the cash itself was posted by each payment. */
    private function cashSession(CashSession $session): ?JournalDraft
    {
        $difference = Decimal::of($session->difference);
        if ($session->status !== CashSessionStatus::Closed || $difference->isZero()) {
            return null;
        }
        $register = $session->cashRegister;
        $cashAccount = $register->cash_account_id ?? $this->cashAccountFor($register->location_id);
        $draft = new JournalDraft($session->closed_at ?? now(), __('finance.posting.cash_difference', ['register' => $register->name]), $register->location_id);

        return $draft->debit($cashAccount, $difference)->credit($this->accounts->id(Role::CashOverShort), $difference);
    }

    /**
     * Accrual of a month's salaries: what each employee earned is owed to them (credit on their account);
     * advances they still owe were debited there when paid, so the payout of the net clears both.
     */
    private function payroll(PayrollRun $run): ?JournalDraft
    {
        if (! $run->status->isPosted()) {
            return null;
        }
        $draft = new JournalDraft($run->posting_date, __('finance.posting.payroll', ['number' => $run->number, 'month' => $run->periodLabel()]), $run->location_id);
        $employees = $this->accounts->id(Role::EmployeeAccounts);
        $total = Decimal::of(0);
        foreach ($run->lines()->orderBy('id')->get() as $line) {
            $draft->credit($employees, $line->earned, $line->contact_id);
            $total = $total->plus(Decimal::of($line->earned));
        }

        return $draft->debit($this->salariesAccount(), $total);
    }

    /** The salaries expense account; installs whose chart has none fall back to general operating expenses. */
    private function salariesAccount(): int
    {
        try {
            return $this->accounts->id(Role::Salaries);
        } catch (DomainException) {
            return $this->accounts->id(Role::OperatingExpense);
        }
    }

    private function cashAccountFor(int $locationId): int
    {
        $method = PaymentMethod::query()->where('type', PaymentMethodType::Cash)->orderBy('sort')->first();
        $id = $method ? (DB::table('location_payment_method')->where('location_id', $locationId)->where('payment_method_id', $method->id)->value('account_id')
            ?? $method->account_id) : null;

        return $id ?? $this->accounts->id(Role::Cash);
    }

    /** Customers live in Receivable, suppliers in Payable; a contact that is both goes by the money's direction. */
    private function controlAccount(Contact $contact, PaymentDirection $direction): int
    {
        if ($contact->type === ContactType::Employee) {
            return $this->accounts->id(Role::EmployeeAccounts);
        }
        $receivable = match ($contact->type) {
            ContactType::Customer => true,
            ContactType::Supplier => false,
            default => $direction === PaymentDirection::In,
        };

        return $this->accounts->id($receivable ? Role::Receivable : Role::Payable);
    }

    /**
     * Σ qty × unit cost of the original stock movements caused by a document's lines (+ in, − out).
     *
     * @param  HasMany<covariant Model, covariant Model>  $items
     */
    private function stockValue(HasMany $items): BigDecimal
    {
        $related = $items->getRelated();
        $value = DB::table('stock_movements')
            ->where('source_type', $related->getMorphClass())
            ->whereIn('source_id', $items->getQuery()->toBase()->select($related->getQualifiedKeyName()))
            ->whereNull('reverses_id')
            ->selectRaw('coalesce(sum(qty * unit_cost), 0) as v')
            ->value('v');

        return Decimal::of((string) $value)->toScale(4, RoundingMode::HalfUp);
    }
}
