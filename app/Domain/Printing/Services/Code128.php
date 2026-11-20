<?php

namespace App\Domain\Printing\Services;

use InvalidArgumentException;

/**
 * Code 128 barcode as inline SVG (no package, no images). Uses set C for all-digit values of even
 * length (denser, typical for EAN-like codes) and set B otherwise — every scanner reads both.
 */
final class Code128
{
    /** Bar/space widths of symbols 0–106 (106 = stop). */
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    private const START_B = 104;

    private const START_C = 105;

    private const STOP = 106;

    /** @return list<int> symbol values including start, checksum and stop */
    public static function encode(string $data): array
    {
        if ($data === '') {
            throw new InvalidArgumentException('Nothing to encode.');
        }

        if (preg_match('/^\d+$/', $data) && strlen($data) % 2 === 0) {
            $codes = [self::START_C];
            foreach (str_split($data, 2) as $pair) {
                $codes[] = (int) $pair;
            }
        } else {
            $codes = [self::START_B];
            foreach (str_split($data) as $char) {
                $ord = ord($char);
                if ($ord < 32 || $ord > 126) {
                    throw new InvalidArgumentException('Only printable ASCII can be encoded.');
                }
                $codes[] = $ord - 32;
            }
        }

        $sum = $codes[0];
        foreach (array_slice($codes, 1) as $i => $code) {
            $sum += ($i + 1) * $code;
        }
        $codes[] = $sum % 103;
        $codes[] = self::STOP;

        return $codes;
    }

    /** Alternating bar / space module widths, starting with a bar. @return list<int> */
    public static function modules(string $data): array
    {
        $widths = [];
        foreach (self::encode($data) as $code) {
            foreach (str_split(self::PATTERNS[$code]) as $w) {
                $widths[] = (int) $w;
            }
        }

        return $widths;
    }

    /** Scalable SVG; `height` is in modules (the SVG stretches to its box). Non-ASCII values return an empty string. */
    public static function svg(string $data, int $height = 40, bool $quietZone = true): string
    {
        try {
            $widths = self::modules($data);
        } catch (InvalidArgumentException) {
            return '';
        }
        $x = $quietZone ? 10 : 0;
        $rects = '';
        foreach ($widths as $i => $w) {
            if ($i % 2 === 0) {
                $rects .= "<rect x=\"{$x}\" y=\"0\" width=\"{$w}\" height=\"{$height}\"/>";
            }
            $x += $w;
        }
        $total = $x + ($quietZone ? 10 : 0);

        return "<svg xmlns=\"http://www.w3.org/2000/svg\" viewBox=\"0 0 {$total} {$height}\" preserveAspectRatio=\"none\" "
            ."width=\"100%\" height=\"100%\" shape-rendering=\"crispEdges\"><g fill=\"#000\">{$rects}</g></svg>";
    }
}
