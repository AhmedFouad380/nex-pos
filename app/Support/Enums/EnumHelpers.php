<?php

namespace App\Support\Enums;

trait EnumHelpers
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Accepts an enum instance, its backing value or blank (Filament form state can be any of these). */
    public static function coerce(mixed $value): ?static
    {
        if ($value instanceof static) {
            return $value;
        }

        return $value === null || $value === '' ? null : static::tryFrom((string) $value);
    }

    /** value => translated label, ready for Filament selects. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
