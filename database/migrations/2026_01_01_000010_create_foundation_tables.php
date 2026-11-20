<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 10);
            $table->unsignedTinyInteger('precision')->default(2);
            $table->string('symbol_position', 10)->default('after'); // before|after
            $table->string('thousand_separator', 3)->default(',');
            $table->string('decimal_separator', 3)->default('.');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('alt_phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_active')->default(true);
            // FKs to price_groups / invoice_layouts / printers are added by later migrations.
            $table->unsignedBigInteger('default_price_group_id')->nullable();
            $table->unsignedBigInteger('invoice_layout_id')->nullable();
            $table->unsignedBigInteger('receipt_layout_id')->nullable();
            $table->unsignedBigInteger('printer_id')->nullable();
            $table->json('custom_fields')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('location_user', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['location_id', 'user_id']);
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 40);
            $table->foreignId('location_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('prefix', 20)->default('');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->timestamps();
            $table->unique(['document_type', 'location_id', 'year'], 'document_sequences_scope_unique');
        });

        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('entity', 40); // product|contact|sale|purchase|location|user|expense
            $table->string('key', 60);
            $table->string('label');
            $table->string('type', 20)->default('text'); // text|number|date|select|bool
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('show_on_invoice')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
            $table->unique(['entity', 'key']);
        });

        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('notable');
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->boolean('is_private')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
        Schema::dropIfExists('custom_field_definitions');
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('location_user');
        Schema::dropIfExists('locations');
        Schema::dropIfExists('currencies');
    }
};
