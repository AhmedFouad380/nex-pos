<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PosSettings extends Settings
{
    public bool $require_open_register;

    public bool $enable_draft;

    public bool $enable_express_checkout;

    public bool $enable_card_payment;

    public bool $enable_multiple_payments;

    public bool $enable_suspend;

    public bool $enable_credit_sale;

    public bool $enable_quotation;

    public bool $allow_discount;

    public bool $allow_order_tax;

    /** still gated by permission */
    public bool $allow_price_edit;

    public bool $show_product_grid;

    public bool $show_featured_products;

    public bool $show_recent_transactions;

    public bool $enable_service_staff;

    public bool $require_service_staff;

    public bool $enable_cash_denominations;

    public bool $enable_customer_display;

    public bool $print_receipt_on_finalize;

    public array $keyboard_shortcuts;

    public static function group(): string
    {
        return 'pos';
    }
}
