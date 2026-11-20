<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class LoyaltySettings extends Settings
{
    public bool $enabled;

    public string $name;

    /** spend X to earn 1 point */
    public float $amount_per_point;

    public float $min_order_total_to_earn;

    public ?int $max_points_per_order;

    public float $redeem_amount_per_point;

    public float $min_order_total_to_redeem;

    public ?int $min_redeem_points;

    public ?int $max_redeem_points;

    public ?int $expiry_period;

    /** month|year */
    public string $expiry_unit;

    public static function group(): string
    {
        return 'loyalty';
    }
}
