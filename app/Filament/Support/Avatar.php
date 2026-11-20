<?php

namespace App\Filament\Support;

use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\HtmlString;

/**
 * A soft circle (people) or rounded square (items) with the first letter of the name, shown before the
 * name in tables. One calm tint of the brand colour for everyone — a table full of colours tires the eye.
 */
final class Avatar
{
    public static function html(?string $name, bool $square = false): HtmlString
    {
        $name = trim((string) $name);
        if ($name === '') {
            return new HtmlString('—');
        }
        $letter = e(mb_strtoupper(mb_substr(preg_replace('/^(ال|al-)/u', '', $name) ?: $name, 0, 1)));
        $radius = $square ? '.45rem' : '999px';

        return new HtmlString(
            '<span style="display:inline-flex;align-items:center;gap:.55rem">'
            ."<span aria-hidden=\"true\" style=\"flex:none;width:1.9rem;height:1.9rem;border-radius:{$radius};display:inline-flex;align-items:center;"
            ."justify-content:center;font-weight:700;font-size:.85rem;color:var(--primary-700);background:color-mix(in oklab,var(--primary-500) 13%,transparent)\">{$letter}</span>"
            .'<span>'.e($name).'</span></span>'
        );
    }

    /** Turns a name column into "avatar + name". */
    public static function column(TextColumn $column, bool $square = false): TextColumn
    {
        return $column->formatStateUsing(fn (?string $state) => self::html($state, $square))->html();
    }
}
