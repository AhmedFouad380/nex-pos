<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\ContactOpeningBalance;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Actions\AllocatePayment;
use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Receipt (140) and payment (7, 3) vouchers. VoucherHolder says who the voucher is for:
 *   2 customer · 3 supplier · 4 employee → one payment to that contact (SCEId)
 *   1 accounts → one payment per detail line: a contact account → payment to that contact; an expense account →
 *     an expense (category = the account) paid by the voucher; any other account → a general voucher on it.
 * Money side: the old vouchers mostly paid from the cashier's custody (an employee account); here money is in the
 * branch cash box, like the sales cash. A voucher on a real box / bank keeps that account.
 * Then the old voucher → invoice links (BrokerPayment) become payment allocations.
 */
final class VouchersStage implements Stage
{
    private const TYPES = [7 => PaymentDirection::Out, 3 => PaymentDirection::Out, 140 => PaymentDirection::In];

    private ?int $cashMethod = null;

    private ImportContext $context;

    /** @var array<string, int> old expense account code => category id */
    private array $categories = [];

    /** @var array<string, string> old contact id => its account, for contacts whose card was deleted later */
    private array $deletedCards = [];

    public function name(): string
    {
        return 'vouchers';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        $this->context = $context;
        $this->cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id');
        foreach ($package->rows('SupCusEmpHistory') as $h) {
            if (filled($h['AccountId'] ?? null)) {
                $this->deletedCards[(string) $h['Id']] = (string) $h['AccountId'];
            }
        }
        $details = [];
        foreach ($package->rows('FinancialTransactionsDetails') as $d) {
            $details[ImportContext::key($d['BranchId'], $d['JVType'], $d['FYId'], $d['FTId'])][] = $d;
        }
        $vouchers = array_values(array_filter($package->all('FinancialTransactions'), fn ($f) => isset(self::TYPES[(int) $f['JVType']])));
        usort($vouchers, fn ($a, $b) => [$a['FTDate'], $a['FTId']] <=> [$b['FTDate'], $b['FTId']]);
        $total = count($vouchers);
        $done = 0;

        ContactLedger::deferring(function () use ($vouchers, $details, $package, $total, &$done) {
            foreach (array_chunk($vouchers, 250) as $chunk) {
                DB::transaction(function () use ($chunk, $details) {
                    foreach ($chunk as $v) {
                        $this->voucher($v, $details[ImportContext::key($v['BranchId'], $v['JVType'], $v['FYId'], $v['FTId'])] ?? []);
                    }
                });
                $done += count($chunk);
                $this->context->progress($this->name(), $done, $total);
            }
            DB::transaction(fn () => $this->allocations($package));
        });
    }

    /** @param list<array<string, mixed>> $lines */
    private function voucher(array $v, array $lines): void
    {
        $key = ImportContext::key($v['BranchId'], $v['JVType'], $v['FTId']);
        if ($this->context->newId('FinancialTransactions', $key.'|0')) {
            return;
        }
        $direction = self::TYPES[(int) $v['JVType']];
        $location = $this->context->newId('Branch', (string) $v['BranchId']) ?? $this->context->newId('Branch', '1');
        $date = Carbon::parse($v['FTDate']);
        [$money, $payer] = $this->moneySide((string) $v['AccountId']);
        $session = filled($v['Shift_Id'] ?? null) ? $this->context->newId('POSTransactions', ImportContext::key($v['BranchId'], $v['Shift_Id'])) : null;
        $reference = ($direction === PaymentDirection::In ? 'RV' : 'PV').$v['BranchId'].'-'.substr((string) $v['FTDate'], 2, 2).'-'.($v['FTSerial'] ?: $v['FTId']);
        $note = trim((string) ($v['Description'] ?? '')) ?: null;

        if ((int) $v['VoucherHolder'] !== 1 || $lines === []) {
            $contact = $this->contact($v['SCEId'], (int) $v['VoucherHolder']);
            $payment = $this->pay($direction, $v['TotalAmount'], $date, $location, $money, $session, $reference, $note, contactId: $contact);
            $payment && $this->context->remember('FinancialTransactions', $key.'|0', $payment);
            $this->payerSide($payer, $direction, $v['TotalAmount'], $date, $location, $session, $reference, $note, $key);

            return;
        }

        foreach ($lines as $i => $line) {
            $amount = Decimal::of($line['AmountLocal'] ?? $line['Amount'] ?? 0);
            $code = (string) $line['AccountId'];
            $lineNote = trim((string) ($line['Description'] ?? '')) ?: $note;
            $target = null;
            if ($this->context->newType('ChartOfAccount', $code) === 'contact') {
                $target = $this->pay($direction, $amount, $date, $location, $money, $session, $reference, $lineNote, contactId: $this->context->newId('ChartOfAccount', $code));
            } elseif ($account = Account::query()->find($this->context->newId('ChartOfAccount', $code))) {
                $target = $direction === PaymentDirection::Out && $account->type === AccountType::Expense
                    ? $this->expense($account, $amount, $date, $location, $money, $session, $reference, $lineNote)
                    : $this->pay($direction, $amount, $date, $location, $money, $session, $reference, $lineNote, counterAccountId: $this->postable($account));
            } else {
                $this->context->warn(__('legacy.warnings.document_skipped', ['doc' => $reference, 'reason' => 'account '.$code]));
            }
            if ($target) {
                $this->context->remember('FinancialTransactions', $key.'|'.$i, $target);
            }
        }
        $this->payerSide($payer, $direction, $v['TotalAmount'], $date, $location, $session, $reference, $note, $key);
    }

    /**
     * Money side of the old voucher:
     *   a custody → that custody box · a box / bank → itself · another contact → the branch box, and that contact
     *   gets the mirror payment (they paid out of their own pocket, or received on the business's behalf).
     *
     * @return array{0: ?int, 1: ?int} [money account id or null for the branch box, paying contact id]
     */
    private function moneySide(string $code): array
    {
        if ($custody = $this->context->newId('Custody', $code)) {
            return [$custody, null];
        }
        if ($this->context->newType('ChartOfAccount', $code) === 'contact') {
            return [null, $this->context->newId('ChartOfAccount', $code)];
        }

        return [$this->moneyAccount($code), null];
    }

    private function payerSide(?int $payer, PaymentDirection $direction, mixed $amount, Carbon $date, int $location, ?int $session, string $reference, ?string $note, string $key): void
    {
        if ($payer === null) {
            return;
        }
        $mirror = $this->pay($direction === PaymentDirection::Out ? PaymentDirection::In : PaymentDirection::Out, $amount, $date, $location, null,
            $session, $reference, $note, contactId: $payer);
        $mirror && $this->context->remember('FinancialTransactions', $key.'|payer', $mirror);
    }

    private function pay(PaymentDirection $direction, mixed $amount, Carbon $date, int $location, ?int $money, ?int $session, string $reference,
        ?string $note, ?int $contactId = null, ?int $counterAccountId = null): ?Payment
    {
        if (! Decimal::of($amount)->isPositive() || ($contactId === null && $counterAccountId === null)) {
            return null;
        }
        $payment = app(RecordPayment::class)->handle(new PaymentData(
            direction: $direction, paymentMethodId: (int) $this->cashMethod, amount: Decimal::toDb($amount), contactId: $contactId,
            locationId: $location, paidAt: $date, accountId: $money, counterAccountId: $counterAccountId, cashSessionId: $session,
            reference: $reference, note: $note,
        ));
        $this->context->count($this->name(), $contactId ? 'contact vouchers' : 'general vouchers');

        return $payment;
    }

    private function expense(Account $account, mixed $amount, Carbon $date, int $location, ?int $money, ?int $session, string $reference, ?string $note): ?Model
    {
        if (! Decimal::of($amount)->isPositive()) {
            return null;
        }
        $category = $this->categories[$account->code] ??= ExpenseCategory::query()->firstOrCreate(
            ['account_id' => $account->id], ['name' => $account->name, 'code' => $account->code])->id;
        $expense = app(CreateExpense::class)->handle([
            'location_id' => $location, 'expense_category_id' => $category, 'amount' => Decimal::toDb($amount), 'expense_date' => $date,
            'ref_no' => $reference, 'note' => $note, 'cash_session_id' => $session,
        ], ['payment_method_id' => (int) $this->cashMethod, 'account_id' => $money]);
        $this->context->count($this->name(), 'expenses');

        return $expense;
    }

    /** A box / bank of the old system keeps its account; a cashier's custody (employee account) → the branch cash box. */
    private function moneyAccount(string $code): ?int
    {
        $id = $this->context->newType('ChartOfAccount', $code) === 'account' ? $this->context->newId('ChartOfAccount', $code) : null;

        return $id && in_array(Account::query()->whereKey($id)->value('sub_type'), ['cash', 'bank'], true) ? $id : null;
    }

    /** Vouchers may name a heading account; post to it only if it accepts entries. */
    private function postable(Account $account): ?int
    {
        if ($account->is_postable) {
            return $account->id;
        }
        $this->context->warn(__('legacy.warnings.document_skipped', ['doc' => $account->code, 'reason' => 'heading account']));

        return null;
    }

    private function contact(mixed $sceId, int $holder): int
    {
        $id = $this->context->newId('SupCusEmp', (string) $sceId);
        if ($id) {
            return $id;
        }
        // a card deleted after its vouchers were made: the old ledger still posts them on the card's account
        $account = $this->deletedCards[(string) $sceId] ?? null;
        if ($account !== null && $this->context->newType('ChartOfAccount', $account) === 'contact') {
            return (int) $this->context->newId('ChartOfAccount', $account);
        }
        [$type, $code] = match ($holder) {
            3 => [ContactType::Supplier, 'S-UNKNOWN'],
            4 => [ContactType::Employee, 'E-UNKNOWN'],
            default => [ContactType::Customer, 'C-UNKNOWN'],
        };

        return Contact::query()->firstOrCreate(['code' => $code], ['type' => $type, 'name' => __('legacy.unknown_contact')])->id;
    }

    /** Old voucher → invoice links. Payment-to-payment links (loan repayments) need no allocation: balances already net. */
    private function allocations(LegacyPackage $package): void
    {
        foreach ($package->rows('BrokerPayment') as $link) {
            $source = (int) ($link['Source'] ?? 0);
            if (! isset(self::TYPES[$source]) || $this->context->newId('BrokerPayment', $linkKey = ImportContext::key($link['BranchId'], $link['InvoiceType'], $link['FYId'], $link['InvoiceId'], $link['PaymentId'], $source, $link['SourceId']))) {
                continue;
            }
            $payment = Payment::query()->find($this->context->newId('FinancialTransactions', ImportContext::key($link['SourceBranchId'] ?: $link['BranchId'], $source, $link['SourceId']).'|0'));
            $document = $this->document($link, $payment);
            if (! $payment || ! $document) {
                continue;
            }
            try {
                $amount = min((float) $link['PaymentValue'], (float) $document->getAttribute('due_total'), (float) app(AllocatePayment::class)->unallocated($payment)->toFloat());
                if ($amount > 0) {
                    app(AllocatePayment::class)->handle($payment, [[$document, Decimal::toDb((string) $amount)]]);
                    $this->context->remember('BrokerPayment', $linkKey, $payment);
                    $this->context->count($this->name(), 'allocations');
                }
            } catch (DomainException $e) {
                $this->context->warn($e->getMessage());
            }
        }
    }

    private function document(array $link, ?Payment $payment): ?Model
    {
        $type = (int) $link['InvoiceType'];
        if (in_array($type, [9, 10, 16, 17], true)) {
            $key = ImportContext::key($link['BranchId'], $type, $link['FYId'], $link['InvoiceId']);
            $id = $this->context->newId('Invoice', $key);
            $class = Relation::getMorphedModel((string) $this->context->newType('Invoice', $key));
            if (! $id || $class === null) {
                return null;
            }

            /** @var class-string<Model> $class */
            return $class::query()->find($id);
        }
        if (in_array($type, [50, 51, 52], true) && $payment?->contact_id) {
            return ContactOpeningBalance::query()->where('contact_id', $payment->contact_id)->whereNull('voided_at')->where('due_total', '>', 0)->first();
        }

        return null;
    }
}
