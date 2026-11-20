<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class SaleSettings extends Settings
{
    public float $default_discount_percent;

    public ?int $default_tax_rate_id;

    /** sell price entered inclusive of tax */
    public bool $prices_include_tax;

    /** tax per line instead of per invoice */
    public bool $enable_line_tax;

    /** disabled|logged_in_user|users|agents */
    public string $commission_agent_mode;

    /** invoice_value|payment_received */
    public string $commission_calculation;

    /** increase_qty|new_line */
    public string $item_addition_method;

    /** none|round_to_whole|round_to_0.5|round_to_0.25 */
    public string $rounding_method;

    public ?float $default_credit_limit;

    public bool $require_pay_term;

    public bool $enable_sales_orders;

    public bool $enable_shipping;

    public bool $show_previous_due_on_invoice;

    public int $quotation_validity_days;

    public static function group(): string
    {
        return 'sale';
    }
}
