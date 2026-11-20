<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;

/** Thin helpers around brick/math so quantities and money never touch floats. */
final class Decimal
{
    /** Scale used for every decimal(18,4) column. */
    public const SCALE = 4;

    public static function of(BigNumber|int|float|string|null $value): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value)) {
            $value = (string) $value; // e.g. 0.1 -> "0.1", not 0.1000000000000000055
        }

        return BigDecimal::of($value);
    }

    /** Rounded string ready for a decimal(18,4) column. */
    public static function toDb(BigNumber|int|float|string|null $value, int $scale = self::SCALE): string
    {
        return (string) self::of($value)->toScale($scale, RoundingMode::HalfUp);
    }

    public static function divide(BigNumber|int|float|string $a, BigNumber|int|float|string $b, int $scale = self::SCALE): BigDecimal
    {
        return self::of($a)->dividedBy(self::of($b), $scale, RoundingMode::HalfUp);
    }
}
