<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeAdvanceSettlement;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** The employee hands (part of) an advance back in cash: a receipt voucher credits their account. */
final class RepayAdvance
{
    public function __construct(
        private readonly RecordPayment $payments,
        private readonly AdvanceBalances $balances,
    ) {}

    public function handle(EmployeeAdvance $advance, string|int|float $amount, int $paymentMethodId, ?CarbonInterface $at = null,
        ?int $accountId = null, ?string $note = null): EmployeeAdvanceSettlement
    {
        $amount = Decimal::of($amount);
        if (! $amount->isPositive()) {
            throw new DomainException(__('finance.errors.non_positive_amount'));
        }

        return DB::transaction(function () use ($advance, $amount, $paymentMethodId, $at, $accountId, $note) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            if ($advance->voided_at !== null) {
                throw new DomainException(__('payroll.errors.advance_voided'));
            }
            if ($amount->isGreaterThan(Decimal::of($advance->remaining))) {
                throw new DomainException(__('payroll.errors.repayment_exceeds', ['remaining' => Decimal::toDb($advance->remaining, 2)]));
            }
            $at ??= now();
            $payment = $this->payments->handle(new PaymentData(
                direction: PaymentDirection::In,
                paymentMethodId: $paymentMethodId,
                amount: Decimal::toDb($amount),
                contactId: $advance->contact_id,
                locationId: $advance->location_id,
                paidAt: $at,
                accountId: $accountId,
                reference: $advance->number,
                note: collect([__('payroll.advance_repayment', ['number' => $advance->number]), $note])->filter()->implode(' - '),
            ));
            $settlement = $advance->settlements()->create(['payment_id' => $payment->id, 'amount' => Decimal::toDb($amount), 'settled_at' => $at]);
            $this->balances->refresh($advance);

            return $settlement;
        });
    }
}
