<?php

namespace App\Domain\Payroll\Services;

use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The arithmetic of one payroll line:
 *   earned = base + allowances + overtime + bonus − absence − penalties − other deductions   (the month's cost)
 *   net    = earned − advance deduction                                                    (handed over)
 */
final class PayrollCalculator
{
    /** Days a month is counted as when pricing a day of absence. */
    public const DAYS_PER_MONTH = 30;

    /** @param array<string, mixed> $line */
    public static function earned(array $line): BigDecimal
    {
        $plus = ['base_salary', 'allowances', 'overtime', 'bonus'];
        $minus = ['absence_deduction', 'penalties', 'other_deductions'];
        $total = Decimal::of(0);
        foreach ($plus as $key) {
            $total = $total->plus(self::amount($line[$key] ?? 0));
        }
        foreach ($minus as $key) {
            $total = $total->minus(self::amount($line[$key] ?? 0));
        }

        return $total;
    }

    /** @param array<string, mixed> $line */
    public static function net(array $line): BigDecimal
    {
        return self::earned($line)->minus(self::amount($line['advance_deduction'] ?? 0));
    }

    /** Form state may hold '' or null for an untouched field. */
    private static function amount(mixed $value): BigDecimal
    {
        return Decimal::of(is_numeric($value) ? $value : 0);
    }

    /** One day's pay × days absent, rounded to 2 decimals. */
    public static function absenceDeduction(string|int|float|null $baseSalary, string|int|float|null $days): BigDecimal
    {
        return self::amount($baseSalary)->multipliedBy(self::amount($days))
            ->dividedBy(self::DAYS_PER_MONTH, 2, RoundingMode::HalfUp);
    }
}
