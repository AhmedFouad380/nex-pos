<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\Finance\Actions\VoidPayment;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeAdvanceSettlement;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Cancels an advance that nothing has repaid yet, with its payment voucher; or cancels one cash repayment. */
final class VoidAdvance
{
    public function __construct(
        private readonly VoidPayment $voidPayment,
        private readonly AdvanceBalances $balances,
    ) {}

    public function handle(EmployeeAdvance $advance, string $reason): EmployeeAdvance
    {
        return DB::transaction(function () use ($advance, $reason) {
            $advance = EmployeeAdvance::query()->lockForUpdate()->findOrFail($advance->id);
            if ($advance->voided_at !== null) {
                throw new DomainException(__('payroll.errors.advance_voided'));
            }
            if ($advance->settlements()->exists()) {
                throw new DomainException(__('payroll.errors.advance_has_settlements'));
            }
            $advance->update(['voided_at' => now(), 'void_reason' => $reason]); // first: VoidPayment refuses vouchers of live advances
            if ($advance->payment && $advance->payment->voided_at === null) {
                $this->voidPayment->handle($advance->payment, $reason);
            }

            return $advance;
        });
    }

    public function voidRepayment(EmployeeAdvanceSettlement $settlement, string $reason): void
    {
        if ($settlement->payment_id === null) {
            throw new DomainException(__('payroll.errors.advance_has_settlements')); // payroll deductions go with their run
        }
        DB::transaction(function () use ($settlement, $reason) {
            $advance = $settlement->advance;
            $payment = $settlement->payment;
            $settlement->delete(); // first: VoidPayment refuses vouchers of live repayments
            if ($payment && $payment->voided_at === null) {
                $this->voidPayment->handle($payment, $reason);
            }
            $this->balances->refresh($advance);
        });
    }
}
