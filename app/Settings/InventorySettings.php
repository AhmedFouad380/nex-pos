<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class InventorySettings extends Settings
{
    /** fifo|lifo|avg (CostingMethod enum) */
    public string $costing_method;

    public bool $enable_brands;

    public bool $enable_categories;

    public bool $enable_racks;

    public bool $enable_lots;

    public bool $enable_expiry;

    public bool $enable_sub_units;

    public bool $enable_secondary_unit;

    public bool $enable_serials;

    /** null = allow selling until expiry */
    public ?int $stop_selling_days_before_expiry;

    public int $expiry_alert_days;

    /** sell below zero stock */
    public bool $allow_overselling;

    public string $sku_prefix;

    public ?int $default_unit_id;

    public static function group(): string
    {
        return 'inventory';
    }
}
