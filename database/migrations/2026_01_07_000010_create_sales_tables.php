<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_types', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Dine-in | Takeaway | Delivery ...
            $table->text('description')->nullable();
            $table->foreignId('price_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('packing_charge_type', 10)->default('fixed'); // fixed|percent
            $table->decimal('packing_charge', 18, 4)->default(0);
            $table->boolean('requires_table')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Service type availability + price group per location (old: types_of_services.location_price_group)
        Schema::create('location_service_type', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_group_id')->nullable()->constrained()->nullOnDelete();
            $table->primary(['location_id', 'service_type_id']);
        });

        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('area')->nullable();
            $table->unsignedSmallInteger('seats')->nullable();
            $table->integer('pos_x')->nullable(); // floor plan
            $table->integer('pos_y')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('customer_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->dateTime('order_date');
            $table->date('delivery_date')->nullable();
            $table->string('status', 20)->default('open'); // open|partial|invoiced|cancelled
            $table->foreignId('price_group_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('shipping_charges', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->text('shipping_address')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'order_date']);
        });

        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_multiplier', 18, 4)->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_invoiced', 18, 4)->default(0);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 40)->nullable()->unique(); // assigned on finalize
            $table->string('draft_no', 40)->nullable()->unique();   // draft / quotation / proforma number
            $table->string('status', 20)->default('draft');         // draft|quotation|proforma|suspended|final
            $table->string('channel', 10)->default('pos');          // pos|direct|import|online
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('sale_date');
            $table->date('due_date')->nullable();
            $table->date('valid_until')->nullable(); // quotations
            $table->date('delivery_date')->nullable();
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete(); // snapshot for reports
            $table->foreignId('price_group_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_type_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('commission_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable(); // fixed|percent
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0);     // invoice-level + line taxes
            $table->decimal('shipping_charges', 18, 4)->default(0);
            $table->decimal('packing_charge', 18, 4)->default(0);
            $table->decimal('round_off', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_total', 18, 4)->default(0);
            $table->decimal('due_total', 18, 4)->default(0);
            $table->string('payment_status', 10)->default('due'); // due|partial|paid
            $table->decimal('cost_total', 18, 4)->default(0);     // COGS, computed on finalize
            $table->integer('points_earned')->default(0);
            $table->integer('points_redeemed')->default(0);
            $table->decimal('points_redeem_amount', 18, 4)->default(0);
            $table->string('kitchen_status', 20)->nullable();     // received|cooked|served
            $table->string('shipping_status', 20)->nullable();    // ordered|packed|shipped|delivered|cancelled
            $table->text('shipping_address')->nullable();
            $table->text('shipping_details')->nullable();
            $table->string('delivered_to')->nullable();
            $table->foreignId('delivery_person_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->text('staff_note')->nullable();
            $table->ulid('public_token')->unique(); // /invoice/{token}, /quote/{token}, /pay/{token}
            $table->string('import_batch', 40)->nullable()->index();
            $table->json('custom_fields')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['location_id', 'sale_date']);
            $table->index(['customer_id', 'sale_date']);
            $table->index(['status', 'payment_status']);
            $table->index(['created_by', 'sale_date']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_item_id')->nullable()->constrained('sale_items')->cascadeOnDelete();
            $table->string('child_type', 10)->nullable(); // modifier|combo
            $table->foreignId('sales_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete(); // denormalized for reports
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_multiplier', 18, 4)->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('base_qty', 18, 4);
            $table->decimal('secondary_qty', 18, 4)->nullable();
            $table->decimal('unit_price_before_discount', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete(); // scheduled discount applied
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0); // per unit
            $table->decimal('unit_price_inc_tax', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);  // from stock_layer_consumptions
            $table->decimal('cost_total', 18, 4)->default(0);
            $table->decimal('qty_returned', 18, 4)->default(0);
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warranty_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('kitchen_station_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kitchen_status', 20)->nullable(); // pending|cooking|ready|served
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('variation_id');
            $table->index('product_id');
        });

        Schema::create('serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->string('serial', 100);
            $table->string('status', 20)->default('in_stock'); // in_stock|sold|returned|damaged
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['variation_id', 'serial']);
            $table->index('serial');
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete(); // null = standalone / credit note
            $table->foreignId('customer_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('return_date');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('refunded_total', 18, 4)->default(0);
            $table->decimal('due_total', 18, 4)->default(0);
            $table->string('payment_status', 10)->default('due');
            $table->decimal('cost_total', 18, 4)->default(0);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'return_date']);
            $table->index(['location_id', 'return_date']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_multiplier', 18, 4)->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('base_qty', 18, 4);
            $table->decimal('unit_price', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->boolean('restock')->default(true);
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sale_item_serials', function (Blueprint $table) {
            $table->foreignId('sale_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('serial_id')->constrained()->restrictOnDelete();
            $table->primary(['sale_item_id', 'serial_id']);
        });

        Schema::create('recurring_profiles', function (Blueprint $table) {
            $table->id();
            $table->morphs('recurrable'); // template sale | expense
            $table->unsignedSmallInteger('interval')->default(1);
            $table->string('interval_unit', 10)->default('month'); // day|week|month|year
            $table->unsignedTinyInteger('repeat_on')->nullable(); // day of month for monthly profiles
            $table->unsignedInteger('repetitions')->nullable(); // null = forever
            $table->unsignedInteger('generated_count')->default(0);
            $table->dateTime('next_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('stopped_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'next_run_at']);
        });
    }

    public function down(): void
    {
        foreach (['recurring_profiles', 'sale_item_serials', 'sale_return_items', 'sale_returns', 'serials',
            'sale_items', 'sales', 'sales_order_items', 'sales_orders', 'kitchen_stations',
            'restaurant_tables', 'location_service_type', 'service_types'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
