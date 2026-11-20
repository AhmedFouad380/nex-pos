<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class LabelSettings extends Settings
{
    /** payment method custom labels */
    public array $custom_payment_labels;

    /** entity => [key => label] overrides */
    public array $custom_field_labels;

    public static function group(): string
    {
        return 'labels';
    }
}
