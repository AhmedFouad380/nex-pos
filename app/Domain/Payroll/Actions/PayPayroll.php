<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\PayrollTotals;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Hands salaries over: one payment voucher per employee for their net, all from the same cash box / bank.
 * Pays every unpaid line, or only the ones picked (the rest can be paid later).
 */
final class PayPayroll
{
    public function __construct(
        private readonly RecordPayment $payments,
        private readonly PayrollTotals $totals,
    ) {}

    /**
     * @param  ?list<int>  $lineIds  null = every unpaid line
     * @return int number of salaries paid
     */
    public function handle(PayrollRun $run, int $paymentMethodId, ?array $lineIds = null, ?CarbonInterface $paidAt = null,
        ?int $accountId = null, ?int $cashSessionId = null): int
    {
        return DB::transaction(function () use ($run, $paymentMethodId, $lineIds, $paidAt, $accountId, $cashSessionId) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->voided_at !== null) {
                throw new DomainException(__('payroll.errors.already_voided'));
            }
            if (! $run->status->isPosted()) {
                throw new DomainException(__('payroll.errors.not_approved'));
            }
            $lines = $run->lines()->with('contact.employeeProfile')
                ->when($lineIds !== null, fn ($query) => $query->whereKey($lineIds))
                ->get()->reject->isPaid();
            if ($lines->isEmpty()) {
                throw new DomainException(__('payroll.errors.nothing_to_pay'));
            }
            $fallbackLocation = $run->location_id ?? Location::query()->orderBy('id')->value('id');

            foreach ($lines as $line) {
                $payment = $this->payments->handle(new PaymentData(
                    direction: PaymentDirection::Out,
                    paymentMethodId: $paymentMethodId,
                    amount: $line->net,
                    contactId: $line->contact_id,
                    locationId: $run->location_id ?? $line->contact->employeeProfile->location_id ?? $fallbackLocation,
                    paidAt: $paidAt ?? now(),
                    accountId: $accountId,
                    cashSessionId: $cashSessionId,
                    reference: $run->number,
                    note: __('payroll.salary_for', ['month' => $run->periodLabel(), 'number' => $run->number]),
                ));
                $line->update(['payment_id' => $payment->id]);
            }
            $this->totals->refresh($run);

            return $lines->count();
        });
    }
}
