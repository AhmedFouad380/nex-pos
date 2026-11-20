<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // GeneralSettings
        $this->migrator->add('general.business_name', 'My Business');
        $this->migrator->add('general.currency_code', 'EGP');
        $this->migrator->add('general.currency_precision', 2);
        $this->migrator->add('general.quantity_precision', 2);
        $this->migrator->add('general.currency_symbol_position', 'after');
        $this->migrator->add('general.timezone', 'Africa/Cairo');
        $this->migrator->add('general.date_format', 'd/m/Y');
        $this->migrator->add('general.time_format', '24');
        $this->migrator->add('general.fy_start_month', 1);
        $this->migrator->add('general.start_date', null);
        $this->migrator->add('general.theme_color', 'amber');
        $this->migrator->add('general.default_locale', 'ar');
        $this->migrator->add('general.tax_label_1', 'الرقم الضريبي');
        $this->migrator->add('general.tax_number_1', null);
        $this->migrator->add('general.tax_label_2', 'السجل التجاري');
        $this->migrator->add('general.tax_number_2', null);
        $this->migrator->add('general.code_label_1', null);
        $this->migrator->add('general.code_1', null);
        $this->migrator->add('general.code_label_2', null);
        $this->migrator->add('general.code_2', null);
        $this->migrator->add('general.address', null);
        $this->migrator->add('general.phone', null);
        $this->migrator->add('general.email', null);

        // InventorySettings
        $this->migrator->add('inventory.costing_method', 'fifo');
        $this->migrator->add('inventory.enable_brands', true);
        $this->migrator->add('inventory.enable_categories', true);
        $this->migrator->add('inventory.enable_racks', false);
        $this->migrator->add('inventory.enable_lots', false);
        $this->migrator->add('inventory.enable_expiry', false);
        $this->migrator->add('inventory.enable_sub_units', true);
        $this->migrator->add('inventory.enable_secondary_unit', false);
        $this->migrator->add('inventory.enable_serials', false);
        $this->migrator->add('inventory.stop_selling_days_before_expiry', null);
        $this->migrator->add('inventory.expiry_alert_days', 30);
        $this->migrator->add('inventory.allow_overselling', false);
        $this->migrator->add('inventory.sku_prefix', '');
        $this->migrator->add('inventory.default_unit_id', null);

        // SaleSettings
        $this->migrator->add('sale.default_discount_percent', 0);
        $this->migrator->add('sale.default_tax_rate_id', null);
        $this->migrator->add('sale.prices_include_tax', true);
        $this->migrator->add('sale.enable_line_tax', false);
        $this->migrator->add('sale.commission_agent_mode', 'disabled');
        $this->migrator->add('sale.commission_calculation', 'invoice_value');
        $this->migrator->add('sale.item_addition_method', 'increase_qty');
        $this->migrator->add('sale.rounding_method', 'none');
        $this->migrator->add('sale.default_credit_limit', null);
        $this->migrator->add('sale.require_pay_term', false);
        $this->migrator->add('sale.enable_sales_orders', false);
        $this->migrator->add('sale.enable_shipping', false);
        $this->migrator->add('sale.show_previous_due_on_invoice', true);
        $this->migrator->add('sale.quotation_validity_days', 15);

        // PurchaseSettings
        $this->migrator->add('purchase.enable_purchase_status', true);
        $this->migrator->add('purchase.enable_editing_product_from_purchase', true);
        $this->migrator->add('purchase.enable_foreign_currency', false);
        $this->migrator->add('purchase.default_currency_code', null);
        $this->migrator->add('purchase.default_exchange_rate', 1);
        $this->migrator->add('purchase.default_profit_percent', 25);
        $this->migrator->add('purchase.enable_purchase_orders', false);
        $this->migrator->add('purchase.enable_purchase_requisitions', false);

        // PosSettings
        $this->migrator->add('pos.require_open_register', true);
        $this->migrator->add('pos.enable_draft', true);
        $this->migrator->add('pos.enable_express_checkout', true);
        $this->migrator->add('pos.enable_card_payment', true);
        $this->migrator->add('pos.enable_multiple_payments', true);
        $this->migrator->add('pos.enable_suspend', true);
        $this->migrator->add('pos.enable_credit_sale', true);
        $this->migrator->add('pos.enable_quotation', true);
        $this->migrator->add('pos.allow_discount', true);
        $this->migrator->add('pos.allow_order_tax', false);
        $this->migrator->add('pos.allow_price_edit', false);
        $this->migrator->add('pos.show_product_grid', true);
        $this->migrator->add('pos.show_featured_products', true);
        $this->migrator->add('pos.show_recent_transactions', true);
        $this->migrator->add('pos.enable_service_staff', false);
        $this->migrator->add('pos.require_service_staff', false);
        $this->migrator->add('pos.enable_cash_denominations', false);
        $this->migrator->add('pos.enable_customer_display', false);
        $this->migrator->add('pos.print_receipt_on_finalize', true);
        $this->migrator->add('pos.keyboard_shortcuts', ['express_checkout' => 'shift+e', 'pay_card' => 'shift+c', 'multiple_pay' => 'shift+m', 'draft' => 'shift+d', 'suspend' => 'shift+s', 'cancel' => 'shift+x', 'focus_search' => 'shift+f', 'add_customer' => 'shift+a']);

        // WeighingScaleSettings
        $this->migrator->add('weighing_scale.enabled', false);
        $this->migrator->add('weighing_scale.label_prefix', '2');
        $this->migrator->add('weighing_scale.sku_length', 5);
        $this->migrator->add('weighing_scale.qty_integer_length', 3);
        $this->migrator->add('weighing_scale.qty_decimal_length', 3);

        // LoyaltySettings
        $this->migrator->add('loyalty.enabled', false);
        $this->migrator->add('loyalty.name', 'نقاط الولاء');
        $this->migrator->add('loyalty.amount_per_point', 1);
        $this->migrator->add('loyalty.min_order_total_to_earn', 0);
        $this->migrator->add('loyalty.max_points_per_order', null);
        $this->migrator->add('loyalty.redeem_amount_per_point', 0.1);
        $this->migrator->add('loyalty.min_order_total_to_redeem', 0);
        $this->migrator->add('loyalty.min_redeem_points', null);
        $this->migrator->add('loyalty.max_redeem_points', null);
        $this->migrator->add('loyalty.expiry_period', null);
        $this->migrator->add('loyalty.expiry_unit', 'year');

        // NotificationSettings
        $this->migrator->add('notifications.mail_enabled', false);
        $this->migrator->add('notifications.mail_from_address', null);
        $this->migrator->add('notifications.mail_from_name', null);
        $this->migrator->add('notifications.sms_enabled', false);
        $this->migrator->add('notifications.sms_gateway', 'generic');
        $this->migrator->add('notifications.sms_config', []);
        $this->migrator->add('notifications.whatsapp_enabled', false);
        $this->migrator->add('notifications.whatsapp_config', []);
        $this->migrator->add('notifications.auto_payment_reminders', false);
        $this->migrator->add('notifications.reminder_days_after_due', 3);

        // LabelSettings
        $this->migrator->add('labels.custom_payment_labels', []);
        $this->migrator->add('labels.custom_field_labels', []);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.business_name');
        $this->migrator->deleteIfExists('general.currency_code');
        $this->migrator->deleteIfExists('general.currency_precision');
        $this->migrator->deleteIfExists('general.quantity_precision');
        $this->migrator->deleteIfExists('general.currency_symbol_position');
        $this->migrator->deleteIfExists('general.timezone');
        $this->migrator->deleteIfExists('general.date_format');
        $this->migrator->deleteIfExists('general.time_format');
        $this->migrator->deleteIfExists('general.fy_start_month');
        $this->migrator->deleteIfExists('general.start_date');
        $this->migrator->deleteIfExists('general.theme_color');
        $this->migrator->deleteIfExists('general.default_locale');
        $this->migrator->deleteIfExists('general.tax_label_1');
        $this->migrator->deleteIfExists('general.tax_number_1');
        $this->migrator->deleteIfExists('general.tax_label_2');
        $this->migrator->deleteIfExists('general.tax_number_2');
        $this->migrator->deleteIfExists('general.code_label_1');
        $this->migrator->deleteIfExists('general.code_1');
        $this->migrator->deleteIfExists('general.code_label_2');
        $this->migrator->deleteIfExists('general.code_2');
        $this->migrator->deleteIfExists('general.address');
        $this->migrator->deleteIfExists('general.phone');
        $this->migrator->deleteIfExists('general.email');
        $this->migrator->deleteIfExists('inventory.costing_method');
        $this->migrator->deleteIfExists('inventory.enable_brands');
        $this->migrator->deleteIfExists('inventory.enable_categories');
        $this->migrator->deleteIfExists('inventory.enable_racks');
        $this->migrator->deleteIfExists('inventory.enable_lots');
        $this->migrator->deleteIfExists('inventory.enable_expiry');
        $this->migrator->deleteIfExists('inventory.enable_sub_units');
        $this->migrator->deleteIfExists('inventory.enable_secondary_unit');
        $this->migrator->deleteIfExists('inventory.enable_serials');
        $this->migrator->deleteIfExists('inventory.stop_selling_days_before_expiry');
        $this->migrator->deleteIfExists('inventory.expiry_alert_days');
        $this->migrator->deleteIfExists('inventory.allow_overselling');
        $this->migrator->deleteIfExists('inventory.sku_prefix');
        $this->migrator->deleteIfExists('inventory.default_unit_id');
        $this->migrator->deleteIfExists('sale.default_discount_percent');
        $this->migrator->deleteIfExists('sale.default_tax_rate_id');
        $this->migrator->deleteIfExists('sale.prices_include_tax');
        $this->migrator->deleteIfExists('sale.enable_line_tax');
        $this->migrator->deleteIfExists('sale.commission_agent_mode');
        $this->migrator->deleteIfExists('sale.commission_calculation');
        $this->migrator->deleteIfExists('sale.item_addition_method');
        $this->migrator->deleteIfExists('sale.rounding_method');
        $this->migrator->deleteIfExists('sale.default_credit_limit');
        $this->migrator->deleteIfExists('sale.require_pay_term');
        $this->migrator->deleteIfExists('sale.enable_sales_orders');
        $this->migrator->deleteIfExists('sale.enable_shipping');
        $this->migrator->deleteIfExists('sale.show_previous_due_on_invoice');
        $this->migrator->deleteIfExists('sale.quotation_validity_days');
        $this->migrator->deleteIfExists('purchase.enable_purchase_status');
        $this->migrator->deleteIfExists('purchase.enable_editing_product_from_purchase');
        $this->migrator->deleteIfExists('purchase.enable_foreign_currency');
        $this->migrator->deleteIfExists('purchase.default_currency_code');
        $this->migrator->deleteIfExists('purchase.default_exchange_rate');
        $this->migrator->deleteIfExists('purchase.default_profit_percent');
        $this->migrator->deleteIfExists('purchase.enable_purchase_orders');
        $this->migrator->deleteIfExists('purchase.enable_purchase_requisitions');
        $this->migrator->deleteIfExists('pos.require_open_register');
        $this->migrator->deleteIfExists('pos.enable_draft');
        $this->migrator->deleteIfExists('pos.enable_express_checkout');
        $this->migrator->deleteIfExists('pos.enable_card_payment');
        $this->migrator->deleteIfExists('pos.enable_multiple_payments');
        $this->migrator->deleteIfExists('pos.enable_suspend');
        $this->migrator->deleteIfExists('pos.enable_credit_sale');
        $this->migrator->deleteIfExists('pos.enable_quotation');
        $this->migrator->deleteIfExists('pos.allow_discount');
        $this->migrator->deleteIfExists('pos.allow_order_tax');
        $this->migrator->deleteIfExists('pos.allow_price_edit');
        $this->migrator->deleteIfExists('pos.show_product_grid');
        $this->migrator->deleteIfExists('pos.show_featured_products');
        $this->migrator->deleteIfExists('pos.show_recent_transactions');
        $this->migrator->deleteIfExists('pos.enable_service_staff');
        $this->migrator->deleteIfExists('pos.require_service_staff');
        $this->migrator->deleteIfExists('pos.enable_cash_denominations');
        $this->migrator->deleteIfExists('pos.enable_customer_display');
        $this->migrator->deleteIfExists('pos.print_receipt_on_finalize');
        $this->migrator->deleteIfExists('pos.keyboard_shortcuts');
        $this->migrator->deleteIfExists('weighing_scale.enabled');
        $this->migrator->deleteIfExists('weighing_scale.label_prefix');
        $this->migrator->deleteIfExists('weighing_scale.sku_length');
        $this->migrator->deleteIfExists('weighing_scale.qty_integer_length');
        $this->migrator->deleteIfExists('weighing_scale.qty_decimal_length');
        $this->migrator->deleteIfExists('loyalty.enabled');
        $this->migrator->deleteIfExists('loyalty.name');
        $this->migrator->deleteIfExists('loyalty.amount_per_point');
        $this->migrator->deleteIfExists('loyalty.min_order_total_to_earn');
        $this->migrator->deleteIfExists('loyalty.max_points_per_order');
        $this->migrator->deleteIfExists('loyalty.redeem_amount_per_point');
        $this->migrator->deleteIfExists('loyalty.min_order_total_to_redeem');
        $this->migrator->deleteIfExists('loyalty.min_redeem_points');
        $this->migrator->deleteIfExists('loyalty.max_redeem_points');
        $this->migrator->deleteIfExists('loyalty.expiry_period');
        $this->migrator->deleteIfExists('loyalty.expiry_unit');
        $this->migrator->deleteIfExists('notifications.mail_enabled');
        $this->migrator->deleteIfExists('notifications.mail_from_address');
        $this->migrator->deleteIfExists('notifications.mail_from_name');
        $this->migrator->deleteIfExists('notifications.sms_enabled');
        $this->migrator->deleteIfExists('notifications.sms_gateway');
        $this->migrator->deleteIfExists('notifications.sms_config');
        $this->migrator->deleteIfExists('notifications.whatsapp_enabled');
        $this->migrator->deleteIfExists('notifications.whatsapp_config');
        $this->migrator->deleteIfExists('notifications.auto_payment_reminders');
        $this->migrator->deleteIfExists('notifications.reminder_days_after_due');
        $this->migrator->deleteIfExists('labels.custom_payment_labels');
        $this->migrator->deleteIfExists('labels.custom_field_labels');
    }
};
