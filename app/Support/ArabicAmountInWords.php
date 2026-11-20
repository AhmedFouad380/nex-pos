<?php

namespace App\Support;

use Brick\Math\RoundingMode;

/**
 * تفقيط: writes an amount in Arabic words for vouchers and cheques,
 * e.g. 1250.50 EGP → "فقط ألف ومائتان وخمسون جنيه مصري وخمسون قرش لا غير".
 */
final class ArabicAmountInWords
{
    /** code => [main unit, fraction unit, fractions per unit] */
    private const CURRENCIES = [
        'EGP' => ['جنيه مصري', 'قرش', 100],
        'SAR' => ['ريال سعودي', 'هللة', 100],
        'AED' => ['درهم إماراتي', 'فلس', 100],
        'KWD' => ['دينار كويتي', 'فلس', 1000],
        'BHD' => ['دينار بحريني', 'فلس', 1000],
        'OMR' => ['ريال عماني', 'بيسة', 1000],
        'QAR' => ['ريال قطري', 'درهم', 100],
        'JOD' => ['دينار أردني', 'قرش', 100],
        'IQD' => ['دينار عراقي', 'فلس', 1000],
        'LYD' => ['دينار ليبي', 'درهم', 1000],
        'USD' => ['دولار أمريكي', 'سنت', 100],
        'EUR' => ['يورو', 'سنت', 100],
    ];

    private const ONES = ['', 'واحد', 'اثنان', 'ثلاثة', 'أربعة', 'خمسة', 'ستة', 'سبعة', 'ثمانية', 'تسعة', 'عشرة',
        'أحد عشر', 'اثنا عشر', 'ثلاثة عشر', 'أربعة عشر', 'خمسة عشر', 'ستة عشر', 'سبعة عشر', 'ثمانية عشر', 'تسعة عشر'];

    private const TENS = ['', '', 'عشرون', 'ثلاثون', 'أربعون', 'خمسون', 'ستون', 'سبعون', 'ثمانون', 'تسعون'];

    private const HUNDREDS = ['', 'مائة', 'مائتان', 'ثلاثمائة', 'أربعمائة', 'خمسمائة', 'ستمائة', 'سبعمائة', 'ثمانمائة', 'تسعمائة'];

    /** [one, two, 3–10, 11+] */
    private const SCALES = [
        1_000_000_000 => ['مليار', 'ملياران', 'مليارات', 'مليار'],
        1_000_000 => ['مليون', 'مليونان', 'ملايين', 'مليون'],
        1_000 => ['ألف', 'ألفان', 'آلاف', 'ألف'],
    ];

    public static function money(string|int|float $amount, string $currencyCode = 'EGP'): string
    {
        [$unit, $fractionUnit, $fractions] = self::CURRENCIES[strtoupper($currencyCode)] ?? [$currencyCode, '', 100];
        $value = Decimal::of($amount)->abs()->toScale(strlen((string) $fractions) - 1, RoundingMode::HalfUp);
        [$whole, $fraction] = array_map('intval', explode('.', (string) $value) + [1 => '0']);

        $parts = [];
        if ($whole > 0 || $fraction === 0 || $fractionUnit === '') {
            $parts[] = self::number($whole).' '.$unit;
        }
        if ($fraction > 0 && $fractionUnit !== '') {
            $parts[] = self::number($fraction).' '.$fractionUnit;
        }

        return 'فقط '.implode(' و', $parts).' لا غير';
    }

    public static function number(int $n): string
    {
        if ($n === 0) {
            return 'صفر';
        }

        $parts = [];
        foreach (self::SCALES as $scale => $names) {
            $count = intdiv($n, $scale);
            $n %= $scale;
            if ($count === 0) {
                continue;
            }
            $parts[] = match (true) {
                $count === 1 => $names[0],
                $count === 2 => $names[1],
                $count <= 10 => self::belowThousand($count).' '.$names[2],
                default => self::belowThousand($count).' '.$names[3],
            };
        }
        if ($n > 0) {
            $parts[] = self::belowThousand($n);
        }

        return implode(' و', $parts);
    }

    private static function belowThousand(int $n): string
    {
        $parts = [];
        if ($n >= 100) {
            $parts[] = self::HUNDREDS[intdiv($n, 100)];
            $n %= 100;
        }
        if ($n > 0) {
            $parts[] = $n < 20 ? self::ONES[$n] : (($n % 10 ? self::ONES[$n % 10].' و' : '').self::TENS[intdiv($n, 10)]);
        }

        return implode(' و', $parts);
    }
}
