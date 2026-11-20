<?php

namespace App\Domain\Payroll\Services;

use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\PayrollRun;
use App\Support\Decimal;

/** Keeps a run's cached totals and paid status in step with its lines. */
final class PayrollTotals
{
    public function refresh(PayrollRun $run): PayrollRun
    {
        $lines = $run->lines()->get();
        $sum = fn (string $column) => $lines->reduce(fn ($total, $line) => $total->plus(Decimal::of($line->{$column})), Decimal::of(0));
        $paid = $lines->filter(fn ($line) => $line->payment_id !== null)
            ->reduce(fn ($total, $line) => $total->plus(Decimal::of($line->net)), Decimal::of(0));

        $status = $run->status;
        if ($status->isPosted()) {
            $unpaid = $lines->reject->isPaid()->count();
            $status = match (true) {
                $unpaid === 0 => PayrollStatus::Paid,
                $paid->isPositive() => PayrollStatus::PartiallyPaid,
                default => PayrollStatus::Approved,
            };
        }

        $run->forceFill([
            'total_earned' => Decimal::toDb($sum('earned')),
            'total_advances' => Decimal::toDb($sum('advance_deduction')),
            'total_net' => Decimal::toDb($sum('net')),
            'total_paid' => Decimal::toDb($paid),
            'status' => $status,
        ])->save();

        return $run;
    }
}
