<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Services\Posting;
use App\Domain\Finance\Services\SystemAccounts;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Shared\Enums\DocumentType;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Domain\Shared\Services\PaymentStatusRefresher;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Records an expense (rent, salaries, a bill paid from the drawer…). Its cost is posted to the
 * category's account against "accrued expenses"; paying it (now or later) clears that.
 */
final class CreateExpense
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbers,
        private readonly SystemAccounts $accounts,
        private readonly PaymentStatusRefresher $statuses,
    ) {}

    /**
     * @param  array{location_id: int, expense_category_id?: ?int, amount: string|int|float, tax_rate_id?: ?int,
     *     expense_date?: ?CarbonInterface, ref_no?: ?string, contact_id?: ?int, expense_for_user_id?: ?int, note?: ?string,
     *     cash_session_id?: ?int}  $data  amount is before tax
     * @param  ?array{payment_method_id: int, amount?: string|int|float|null, account_id?: ?int}  $payNow
     */
    public function handle(array $data, ?array $payNow = null): Expense
    {
        $subtotal = Decimal::of($data['amount']);
        if (! $subtotal->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }
        $rate = isset($data['tax_rate_id']) ? Decimal::of(TaxRate::query()->findOrFail($data['tax_rate_id'])->rate) : null;
        $tax = $rate ? $subtotal->multipliedBy($rate)->dividedBy(100, 4, RoundingMode::HalfUp) : Decimal::of(0);
        $total = $subtotal->plus($tax);

        return DB::transaction(function () use ($data, $payNow, $subtotal, $tax, $total) {
            $expense = Expense::query()->create([
                'number' => $this->numbers->next(DocumentType::Expense, $data['location_id']),
                'ref_no' => $data['ref_no'] ?? null,
                'location_id' => $data['location_id'],
                'expense_category_id' => $data['expense_category_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'expense_for_user_id' => $data['expense_for_user_id'] ?? null,
                'cash_session_id' => $data['cash_session_id'] ?? null,
                'expense_date' => $data['expense_date'] ?? now(),
                'subtotal' => Decimal::toDb($subtotal),
                'tax_rate_id' => $data['tax_rate_id'] ?? null,
                'tax_amount' => Decimal::toDb($tax),
                'total' => Decimal::toDb($total),
                'due_total' => Decimal::toDb($total),
                'payment_status' => PaymentStatus::Due,
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ]);
            app(Posting::class)->sync($expense);

            if ($payNow !== null) {
                $this->pay($expense, $payNow['payment_method_id'], $payNow['amount'] ?? $expense->total, $payNow['account_id'] ?? null);
            }

            return $expense->refresh();
        });
    }

    /** Pays (part of) an expense. The voucher has no contact: it settles "accrued expenses". */
    public function pay(Expense $expense, int $paymentMethodId, string|int|float $amount, ?int $accountId = null, ?CarbonInterface $at = null): void
    {
        app(RecordPayment::class)->handle(new PaymentData(
            direction: PaymentDirection::Out,
            paymentMethodId: $paymentMethodId,
            amount: Decimal::toDb($amount),
            locationId: $expense->location_id,
            paidAt: $at ?? $expense->expense_date,
            allocations: [[$expense, Decimal::toDb($amount)]],
            accountId: $accountId,
            counterAccountId: $this->accounts->id(AccountSubType::AccruedExpenses),
            cashSessionId: $expense->cash_session_id,
            note: $expense->note,
        ));
        $this->statuses->refresh($expense);
    }
}
