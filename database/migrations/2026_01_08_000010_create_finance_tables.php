<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every money movement in or out. Receipt / payment vouchers (سندات القبض والصرف) are payments too.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique(); // RV-00001 (in) / PV-00001 (out)
            $table->string('direction', 3);         // in|out
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('counter_account_id')->nullable()->constrained('accounts')->restrictOnDelete(); // non-contact vouchers
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete(); // cash box / bank
            $table->decimal('amount', 18, 4);
            $table->dateTime('paid_at');
            $table->string('reference', 100)->nullable();
            $table->string('cheque_number', 60)->nullable();
            $table->date('cheque_date')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('card_type', 20)->nullable(); // visa|master|meeza|...
            $table->string('card_last4', 4)->nullable();
            $table->string('card_holder')->nullable();
            $table->text('note')->nullable();
            $table->boolean('is_online')->default(false);
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_ref', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['contact_id', 'paid_at']);
            $table->index(['account_id', 'paid_at']);
            $table->index(['direction', 'paid_at']);
            $table->index('cash_session_id');
        });

        // One payment split across many documents. Unallocated remainder = customer/supplier advance.
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->morphs('allocatable'); // sale|purchase|sale_return|purchase_return|expense|contact_opening_balance
            $table->decimal('amount', 18, 4);
            $table->timestamps();
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->date('entry_date');
            $table->string('description')->nullable();
            $table->nullableMorphs('source'); // the document that produced it; null = manual entry
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_manual')->default(false);
            $table->foreignId('reversed_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('entry_date');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('entry_date'); // denormalized for fast ledgers/trial balance
            $table->decimal('debit', 18, 4)->default(0);
            $table->decimal('credit', 18, 4)->default(0);
            $table->foreignId('contact_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('memo')->nullable();
            $table->index(['account_id', 'entry_date']);
            $table->index(['contact_id', 'entry_date']);
        });

        Schema::create('account_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('from_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->dateTime('transferred_at');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // expense GL account
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->string('ref_no', 60)->nullable();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_for_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('expense_date');
            $table->decimal('subtotal', 18, 4)->default(0);
            $table->foreignId('tax_rate_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('total', 18, 4)->default(0);
            $table->decimal('paid_total', 18, 4)->default(0);
            $table->decimal('due_total', 18, 4)->default(0);
            $table->string('payment_status', 10)->default('due');
            $table->boolean('is_refund')->default(false);
            $table->text('note')->nullable();
            $table->json('custom_fields')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'expense_date']);
            $table->index(['expense_category_id', 'expense_date']);
        });
    }

    public function down(): void
    {
        foreach (['expenses', 'expense_categories', 'account_transfers', 'journal_lines',
            'journal_entries', 'payment_allocations', 'payments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
