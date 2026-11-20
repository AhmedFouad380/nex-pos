<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('short_name', 20);
            $table->boolean('allow_decimal')->default(false);
            $table->foreignId('base_unit_id')->nullable()->constrained('units')->restrictOnDelete();
            $table->decimal('base_multiplier', 18, 4)->default(1); // e.g. carton = 12 x piece
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('rate', 7, 4)->default(0); // for groups = sum of members (cached)
            $table->boolean('is_group')->default(false);
            $table->boolean('for_group_only')->default(false); // usable only inside a tax group
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('tax_rate_group', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('tax_rates')->cascadeOnDelete();
            $table->foreignId('tax_rate_id')->constrained('tax_rates')->restrictOnDelete();
            $table->primary(['group_id', 'tax_rate_id']);
        });

        Schema::create('warranties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedInteger('duration');
            $table->string('duration_unit', 10)->default('month'); // day|week|month|year
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('price_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('variation_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('variation_template_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_template_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku', 60)->unique();
            $table->string('type', 20)->default('single'); // single|variable|combo|service|modifier
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('secondary_unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tax_type', 10)->default('exclusive'); // inclusive|exclusive
            $table->string('barcode_type', 10)->default('C128');
            $table->boolean('track_stock')->default(true);
            $table->decimal('alert_qty', 18, 4)->nullable();
            $table->decimal('reorder_qty', 18, 4)->nullable();
            $table->boolean('track_lots')->default(false);
            $table->boolean('track_expiry')->default(false);
            $table->unsignedInteger('expiry_period')->nullable();
            $table->string('expiry_unit', 10)->nullable(); // day|month|year
            $table->boolean('track_serials')->default(false);
            $table->foreignId('warranty_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('weight', 12, 4)->nullable();
            $table->boolean('is_sellable')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('preparation_minutes')->nullable();
            $table->text('description')->nullable();
            $table->json('custom_fields')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();

            $table->index('type');
            $table->index(['is_active', 'is_sellable']);
            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['name', 'sku']);
            }
        });

        // Sub-units allowed when buying/selling this product (old: products.sub_unit_ids)
        Schema::create('product_unit', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->primary(['product_id', 'unit_id']);
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variation_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('DEFAULT');
            $table->json('attribute_values')->nullable(); // {"Color":"Red","Size":"XL"}
            $table->string('sku', 60)->unique();
            $table->decimal('purchase_price', 18, 4)->default(0);
            $table->decimal('purchase_price_inc_tax', 18, 4)->default(0);
            $table->decimal('profit_margin', 9, 4)->default(0);
            $table->decimal('sell_price', 18, 4)->default(0);
            $table->decimal('sell_price_inc_tax', 18, 4)->default(0);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('variation_barcodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained()->cascadeOnDelete();
            $table->string('barcode', 64)->unique();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete(); // barcode of a carton, etc.
            $table->timestamps();
        });

        Schema::create('variation_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('price_group_id')->constrained()->cascadeOnDelete();
            $table->string('price_type', 10)->default('fixed'); // fixed|percent
            $table->decimal('value', 18, 4);
            $table->timestamps();
            $table->unique(['variation_id', 'price_group_id']);
        });

        Schema::create('combo_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('combo_variation_id')->constrained('variations')->cascadeOnDelete();
            $table->foreignId('component_variation_id')->constrained('variations')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->decimal('qty', 18, 4);
            $table->timestamps();
        });

        Schema::create('location_product', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('rack', 30)->nullable();
            $table->string('row', 30)->nullable();
            $table->string('position', 30)->nullable();
            $table->boolean('is_featured')->default(false); // featured on POS for this location
            $table->primary(['location_id', 'product_id']);
        });

        Schema::create('product_modifier_set', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['product_id', 'modifier_product_id']);
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 10)->default('percent'); // fixed|percent
            $table->decimal('value', 18, 4);
            $table->unsignedSmallInteger('priority')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('price_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('applies_with_customer_group')->default(false);
            $table->boolean('applies_with_price_group')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('discountables', function (Blueprint $table) {
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->morphs('discountable'); // variation|product|brand|category
            $table->primary(['discount_id', 'discountable_type', 'discountable_id']);
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->foreign('default_price_group_id')->references('id')->on('price_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['default_price_group_id']);
        });
        foreach ([
            'discountables', 'discounts', 'product_modifier_set', 'location_product', 'combo_items',
            'variation_prices', 'variation_barcodes', 'variations', 'product_attributes', 'product_unit',
            'products', 'variation_template_values', 'variation_templates', 'price_groups', 'warranties',
            'tax_rate_group', 'tax_rates', 'categories', 'brands', 'units',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
