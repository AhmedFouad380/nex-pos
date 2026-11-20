<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->dateTime('requisition_date');
            $table->date('required_by')->nullable();
            $table->string('status', 20)->default('open'); // open|partial|ordered|cancelled
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_requisition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_ordered', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_requisition_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('order_date');
            $table->date('expected_at')->nullable();
            $table->string('status', 20)->default('draft'); // draft|sent|partial|received|cancelled
            $table->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('shipping_charges', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'order_date']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_requisition_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_multiplier', 18, 4)->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('qty_received', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('supplier_ref', 60)->nullable(); // supplier's own invoice number
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('purchase_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('received'); // pending|received
            $table->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('exchange_rate', 18, 6)->default(1);
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('shipping_charges', 18, 4)->default(0);
            $table->text('shipping_details')->nullable();
            $table->decimal('additional_costs_total', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_total', 18, 4)->default(0);
            $table->decimal('due_total', 18, 4)->default(0);
            $table->string('payment_status', 10)->default('due'); // due|partial|paid
            $table->text('note')->nullable();
            $table->string('import_batch', 40)->nullable()->index();
            $table->json('custom_fields')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'purchase_date']);
            $table->index(['location_id', 'purchase_date']);
            $table->index('payment_status');
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_multiplier', 18, 4)->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('base_qty', 18, 4);
            $table->decimal('secondary_qty', 18, 4)->nullable();
            $table->decimal('unit_cost_before_discount', 18, 4)->default(0);
            $table->string('discount_type', 10)->nullable();
            $table->decimal('discount_value', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0);       // per unit
            $table->decimal('unit_cost_inc_tax', 18, 4)->default(0);
            $table->decimal('landed_unit_cost', 18, 4)->default(0); // after distributing additional costs, base currency
            $table->decimal('line_total', 18, 4)->default(0);
            $table->decimal('qty_returned', 18, 4)->default(0);
            $table->string('lot_number', 60)->nullable();
            $table->foreignId('lot_id')->nullable()->constrained()->nullOnDelete();
            $table->date('mfg_date')->nullable();
            $table->date('exp_date')->nullable();
            $table->decimal('new_sell_price', 18, 4)->nullable(); // update selling price from purchase screen
            $table->timestamps();
            $table->index('variation_id');
        });

        // Landed costs (old: additional_expense_key_1..4)
        Schema::create('purchase_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('amount', 18, 4);
            $table->string('distribute_by', 10)->default('value'); // value|qty|weight
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete(); // null = standalone return
            $table->foreignId('supplier_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->dateTime('return_date');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->decimal('discount_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_total', 18, 4)->default(0); // refunded by supplier
            $table->decimal('due_total', 18, 4)->default(0);
            $table->string('payment_status', 10)->default('due');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['supplier_id', 'return_date']);
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_multiplier', 18, 4)->default(1);
            $table->decimal('qty', 18, 4);
            $table->decimal('base_qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->foreignId('lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['purchase_return_items', 'purchase_returns', 'purchase_costs', 'purchase_items',
            'purchases', 'purchase_order_items', 'purchase_orders', 'purchase_requisition_items',
            'purchase_requisitions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
