<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class WeighingScaleSettings extends Settings
{
    public bool $enabled;

    public string $label_prefix;

    public int $sku_length;

    public int $qty_integer_length;

    public int $qty_decimal_length;

    public static function group(): string
    {
        return 'weighing_scale';
    }
}
