<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PurchaseSettings extends Settings
{
    public bool $enable_purchase_status;

    public bool $enable_editing_product_from_purchase;

    public bool $enable_foreign_currency;

    public ?string $default_currency_code;

    public float $default_exchange_rate;

    public float $default_profit_percent;

    public bool $enable_purchase_orders;

    public bool $enable_purchase_requisitions;

    public static function group(): string
    {
        return 'purchase';
    }
}
