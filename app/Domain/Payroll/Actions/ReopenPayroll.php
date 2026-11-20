<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Services\Posting;
use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeAdvanceSettlement;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Domain\Payroll\Services\PayrollTotals;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Undoes an approval that nothing was paid from yet (to fix a mistake), or voids the payroll altogether:
 * the accrual is reversed and the advance deductions it made are given back.
 */
final class ReopenPayroll
{
    public function __construct(
        private readonly AdvanceBalances $advances,
        private readonly PayrollTotals $totals,
        private readonly ContactLedger $ledger,
    ) {}

    /** Back to draft. */
    public function handle(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = $this->lockUnpaid($run);
            if (! $run->status->isPosted()) {
                throw new DomainException(__('payroll.errors.not_approved'));
            }
            $run->forceFill(['status' => PayrollStatus::Draft, 'approved_at' => null, 'approved_by' => null])->save();
            $this->unwind($run);

            return $this->totals->refresh($run);
        });
    }

    /** Cancelled for good (any status). */
    public function void(PayrollRun $run, string $reason): PayrollRun
    {
        return DB::transaction(function () use ($run, $reason) {
            $run = $this->lockUnpaid($run);
            $run->forceFill(['voided_at' => now(), 'void_reason' => $reason])->save();
            $this->unwind($run);

            return $run;
        });
    }

    private function lockUnpaid(PayrollRun $run): PayrollRun
    {
        $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
        if ($run->voided_at !== null) {
            throw new DomainException(__('payroll.errors.already_voided'));
        }
        if ($run->lines()->whereNotNull('payment_id')->exists()) {
            throw new DomainException(__('payroll.errors.has_payments'));
        }

        return $run;
    }

    private function unwind(PayrollRun $run): void
    {
        $lineIds = $run->lines()->pluck('id');
        $advanceIds = EmployeeAdvanceSettlement::query()->whereIn('payroll_line_id', $lineIds)->pluck('employee_advance_id')->unique();
        EmployeeAdvanceSettlement::query()->whereIn('payroll_line_id', $lineIds)->delete();
        foreach (EmployeeAdvance::query()->whereKey($advanceIds)->get() as $advance) {
            $this->advances->refresh($advance);
        }

        app(Posting::class)->sync($run);
        foreach (Contact::query()->whereKey($run->lines()->pluck('contact_id'))->get() as $employee) {
            $this->ledger->refresh($employee);
        }
    }
}
