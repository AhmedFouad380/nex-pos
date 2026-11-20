<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained()->cascadeOnDelete();
            $table->string('number', 60);
            $table->date('mfg_date')->nullable();
            $table->date('exp_date')->nullable();
            $table->timestamps();
            $table->unique(['variation_id', 'number']);
            $table->index('exp_date');
        });

        // Single source of truth for quantities. Never updated, only appended (reversals point to the original).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('qty', 18, 4);       // signed, in base unit
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->string('type', 20);          // purchase|purchase_return|sale|sale_return|adjustment|transfer_in|transfer_out|count|opening
            $table->morphs('source');            // the document line that caused it
            $table->foreignId('reverses_id')->nullable()->constrained('stock_movements')->restrictOnDelete();
            $table->dateTime('moved_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['variation_id', 'location_id', 'moved_at'], 'stock_movements_var_loc_date_idx');
            $table->index(['location_id', 'moved_at']);
        });

        // Cache of SUM(stock_movements.qty) per variation x location — rebuildable via `stock:rebuild`.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained()->cascadeOnDelete();
            $table->decimal('qty_on_hand', 18, 4)->default(0);
            $table->decimal('qty_reserved', 18, 4)->default(0);
            $table->decimal('avg_cost', 18, 4)->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->unique(['location_id', 'variation_id']);
            $table->index('variation_id');
        });

        // FIFO cost layers
        Schema::create('stock_layers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->morphs('source'); // purchase_item | adjustment_item | transfer_item | sale_return_item
            $table->dateTime('received_at');
            $table->date('exp_date')->nullable();
            $table->decimal('original_qty', 18, 4);
            $table->decimal('remaining_qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamps();
            $table->index(['variation_id', 'location_id', 'remaining_qty', 'received_at'], 'stock_layers_fifo_idx');
        });

        Schema::create('stock_layer_consumptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_layer_id')->constrained()->restrictOnDelete();
            $table->morphs('consumer'); // sale_item | adjustment_item | transfer_item | purchase_return_item
            $table->decimal('qty', 18, 4);
            $table->decimal('unit_cost', 18, 4);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->dateTime('adjusted_at');
            $table->string('type', 20); // opening|increase|decrease|damage|expired
            $table->string('reason')->nullable();
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->decimal('recovered_amount', 18, 4)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'adjusted_at']);
        });

        Schema::create('stock_adjustment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_adjustment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_multiplier', 18, 4)->default(1);
            $table->decimal('qty', 18, 4);       // signed
            $table->decimal('base_qty', 18, 4);  // signed, base unit
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->decimal('line_total', 18, 4)->default(0);
            $table->foreignId('lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('lot_number', 60)->nullable(); // for opening/increase creating a new lot
            $table->date('exp_date')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('from_location_id')->constrained('locations')->restrictOnDelete();
            $table->foreignId('to_location_id')->constrained('locations')->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft|in_transit|received|cancelled
            $table->dateTime('transfer_date');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('shipping_cost', 18, 4)->default(0);
            $table->decimal('total_cost', 18, 4)->default(0);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['from_location_id', 'transfer_date']);
            $table->index(['to_location_id', 'transfer_date']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('unit_multiplier', 18, 4)->default(1);
            $table->decimal('qty_sent', 18, 4);
            $table->decimal('qty_received', 18, 4)->default(0);
            $table->decimal('base_qty', 18, 4);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->foreignId('lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('draft'); // draft|counting|posted|cancelled
            $table->string('scope', 20)->default('full');   // full|category|brand|selected
            $table->dateTime('started_at')->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->foreignId('stock_adjustment_id')->nullable()->constrained()->nullOnDelete(); // generated on post
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_id')->constrained()->restrictOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('expected_qty', 18, 4)->default(0);
            $table->decimal('counted_qty', 18, 4)->nullable();
            $table->decimal('difference', 18, 4)->default(0);
            $table->decimal('unit_cost', 18, 4)->default(0);
            $table->timestamps();
            $table->unique(['stock_count_id', 'variation_id', 'lot_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_count_items', 'stock_counts', 'stock_transfer_items', 'stock_transfers',
            'stock_adjustment_items', 'stock_adjustments', 'stock_layer_consumptions', 'stock_layers',
            'stock_levels', 'stock_movements', 'lots'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
