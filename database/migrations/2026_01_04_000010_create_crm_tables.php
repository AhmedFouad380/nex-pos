<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->foreignId('price_group_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 10); // customer|supplier|both
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('business_name')->nullable();
            $table->string('mobile', 30)->nullable();
            $table->string('alt_mobile', 30)->nullable();
            $table->string('landline', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('tax_number', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->text('shipping_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->foreignId('customer_group_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('credit_limit', 18, 4)->nullable();
            $table->unsignedSmallInteger('pay_term_number')->nullable();
            $table->string('pay_term_unit', 10)->nullable(); // day|month
            $table->boolean('is_default')->default(false); // walk-in customer
            $table->boolean('is_active')->default(true);
            $table->date('dob')->nullable();
            // Caches — always updated inside the same DB transaction; rebuildable from journal/loyalty ledgers.
            $table->decimal('balance', 18, 4)->default(0);       // + receivable from them / − payable to them
            $table->integer('loyalty_points')->default(0);
            $table->text('note')->nullable();
            $table->json('custom_fields')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
            $table->index('type');
            $table->index('mobile');
            $table->index('name');
        });

        Schema::create('contact_user', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['contact_id', 'user_id']);
        });

        Schema::create('contact_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 12); // receivable|payable
            $table->decimal('amount', 18, 4);
            $table->decimal('paid_total', 18, 4)->default(0);
            $table->decimal('due_total', 18, 4)->default(0);
            $table->string('payment_status', 10)->default('due');
            $table->date('as_of_date');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->timestamps();
        });

        // Discount granted on a contact's account statement (custom feature of the old system)
        Schema::create('ledger_discounts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('direction', 12); // receivable (lowers what they owe us) | payable (lowers what we owe them)
            $table->date('date');
            $table->decimal('amount', 18, 4);
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'date']);
        });

        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->integer('points'); // + earned / − redeemed or expired
            $table->string('type', 12); // earn|redeem|expire|adjust
            $table->nullableMorphs('source');
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['loyalty_transactions', 'ledger_discounts', 'contact_opening_balances',
            'contact_user', 'contacts', 'customer_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
