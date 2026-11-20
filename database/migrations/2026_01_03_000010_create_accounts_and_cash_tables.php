<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Chart of accounts (double-entry)
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('type', 20);     // asset|liability|equity|income|expense
            $table->string('sub_type', 30)->nullable(); // cash|bank|receivable|payable|inventory|cogs|sales|tax|discount|...
            $table->boolean('is_system')->default(false);   // cannot be deleted
            $table->boolean('is_postable')->default(true);  // leaf accounts only
            $table->boolean('is_active')->default(true);
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->foreignId('currency_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index(['type', 'sub_type']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20); // cash|card|bank_transfer|cheque|wallet|advance|other
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // default destination
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // Per-location enabled methods + destination account (old: business_locations.default_payment_accounts)
        Schema::create('location_payment_method', function (Blueprint $table) {
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_method_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_enabled')->default(true);
            $table->primary(['location_id', 'payment_method_id']);
        });

        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 10)->default('open'); // open|closed
            $table->dateTime('opened_at');
            $table->decimal('opening_float', 18, 4)->default(0);
            $table->dateTime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('expected_cash', 18, 4)->nullable();
            $table->decimal('counted_cash', 18, 4)->nullable();
            $table->decimal('difference', 18, 4)->nullable();
            $table->unsignedInteger('card_slips_count')->default(0);
            $table->unsignedInteger('cheques_count')->default(0);
            $table->json('denominations')->nullable();
            $table->text('closing_note')->nullable();
            $table->timestamps();
            $table->index(['cash_register_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained()->restrictOnDelete();
            $table->string('direction', 3); // in|out
            $table->decimal('amount', 18, 4);
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cash_denominations', function (Blueprint $table) {
            $table->id();
            $table->decimal('value', 18, 4)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['cash_denominations', 'cash_movements', 'cash_sessions', 'cash_registers',
            'location_payment_method', 'payment_methods', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
