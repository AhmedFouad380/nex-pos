<?php

namespace App\Domain\Payroll\Actions;

use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Services\Posting;
use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Payroll\Services\AdvanceBalances;
use App\Domain\Payroll\Services\PayrollTotals;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Locks a payroll: posts the accrual (salaries expense ← employees' accounts) and applies each advance
 * deduction to the employee's open advances, oldest first.
 */
final class ApprovePayroll
{
    public function __construct(
        private readonly AdvanceBalances $advances,
        private readonly PayrollTotals $totals,
        private readonly ContactLedger $ledger,
    ) {}

    public function handle(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = PayrollRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->voided_at !== null || $run->status !== PayrollStatus::Draft) {
                throw new DomainException(__('payroll.errors.not_draft'));
            }
            $lines = $run->lines()->with('contact')->get();
            if ($lines->isEmpty()) {
                throw new DomainException(__('payroll.errors.empty_run'));
            }
            $at = CarbonImmutable::parse($run->posting_date)->endOfDay();

            foreach ($lines as $line) {
                $left = Decimal::of($line->advance_deduction);
                if (! $left->isPositive()) {
                    continue;
                }
                $open = $this->advances->open($line->contact_id, $at);
                $available = $open->reduce(fn (BigDecimal $sum, $a) => $sum->plus(Decimal::of($a->remaining)), Decimal::of(0));
                if ($left->isGreaterThan($available)) {
                    throw new DomainException(__('payroll.errors.advance_exceeds_open', ['name' => $line->contact->name, 'open' => Decimal::toDb($available, 2)]));
                }
                foreach ($open as $advance) {
                    if (! $left->isPositive()) {
                        break;
                    }
                    $take = BigDecimal::min($left, Decimal::of($advance->remaining));
                    $advance->settlements()->create(['payroll_line_id' => $line->id, 'amount' => Decimal::toDb($take), 'settled_at' => $at]);
                    $this->advances->refresh($advance);
                    $left = $left->minus($take);
                }
            }

            $run->forceFill(['status' => PayrollStatus::Approved, 'approved_at' => now(), 'approved_by' => auth()->id()])->save();
            $this->totals->refresh($run);
            app(Posting::class)->sync($run);
            foreach (Contact::query()->whereKey($lines->pluck('contact_id'))->get() as $employee) {
                $this->ledger->refresh($employee);
            }

            return $run->refresh();
        });
    }
}
