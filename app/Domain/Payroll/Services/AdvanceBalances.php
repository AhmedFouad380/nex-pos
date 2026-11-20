<?php

namespace App\Domain\Payroll\Services;

use App\Domain\CRM\Models\Contact;
use App\Domain\Payroll\Enums\AdvanceStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/** What employees still owe on their advances. */
final class AdvanceBalances
{
    /** Recomputes settled / remaining / status of an advance from its settlements. */
    public function refresh(EmployeeAdvance $advance): EmployeeAdvance
    {
        $settled = Decimal::of((string) $advance->settlements()->sum('amount'));
        $remaining = Decimal::of($advance->amount)->minus($settled);
        $advance->forceFill([
            'settled_total' => Decimal::toDb($settled),
            'remaining' => Decimal::toDb($remaining),
            'status' => $remaining->isPositive() ? AdvanceStatus::Open : AdvanceStatus::Settled,
        ])->save();

        return $advance;
    }

    /**
     * Live advances of an employee with something left to repay, oldest first.
     *
     * @return Collection<int, EmployeeAdvance>
     */
    public function open(Contact|int $employee, ?CarbonInterface $until = null): Collection
    {
        return EmployeeAdvance::query()
            ->where('contact_id', $employee instanceof Contact ? $employee->id : $employee)
            ->whereNull('voided_at')->where('remaining', '>', 0)
            ->when($until, fn ($query, $date) => $query->where('advance_date', '<=', $date))
            ->orderBy('advance_date')->orderBy('id')
            ->get();
    }

    public function openTotal(Contact|int $employee, ?CarbonInterface $until = null): BigDecimal
    {
        return $this->open($employee, $until)->reduce(fn (BigDecimal $sum, EmployeeAdvance $a) => $sum->plus(Decimal::of($a->remaining)), Decimal::of(0));
    }

    /** This month's suggested deduction: each open advance's installment (or all of it when it has none). */
    public function suggestedDeduction(Contact|int $employee, CarbonInterface $until): BigDecimal
    {
        return $this->open($employee, $until)->reduce(function (BigDecimal $sum, EmployeeAdvance $a) {
            $remaining = Decimal::of($a->remaining);
            $installment = $a->installment_amount !== null ? BigDecimal::min(Decimal::of($a->installment_amount), $remaining) : $remaining;

            return $sum->plus($installment);
        }, Decimal::of(0));
    }
}
