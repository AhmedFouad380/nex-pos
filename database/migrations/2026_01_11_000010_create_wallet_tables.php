<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile wallets (Vodafone / Etisalat / Orange Cash, InstaPay): phones running the companion app read the
 * provider's SMS and send them here; each one waits as a pending transaction until an employee confirms
 * what it was for — only then is a receipt / payment voucher recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        // One wallet number (SIM) the business owns, and the money account its balance lives in.
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20); // vodafone_cash|etisalat_cash|orange_cash|instapay
            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete(); // default: the wallet payment method's account
            $table->decimal('last_balance', 18, 4)->nullable(); // as the provider last reported it
            $table->dateTime('last_balance_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['provider', 'phone']);
        });

        // How to read a provider's message: the text with {placeholders} where the values are.
        Schema::create('wallet_message_templates', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20);
            $table->string('direction', 3); // in|out
            $table->string('name');
            $table->string('sender', 60)->nullable(); // SMS sender id (e.g. VF-Cash); null = any
            $table->text('template');
            $table->string('date_format', 30)->default('H:i y-m-d');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('wallet_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->string('platform', 20)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('wallet_message_template_id')->nullable()->constrained()->nullOnDelete();
            // the message exactly as received — kept so it can be read again when a template changes
            $table->string('sender', 60)->nullable();
            $table->text('body');
            $table->char('body_hash', 64)->unique(); // the same SMS caught by two phones counts once
            $table->dateTime('received_at');
            $table->unsignedTinyInteger('sim_slot')->nullable();
            // what the template read out of it
            $table->string('provider', 20)->nullable();
            $table->string('direction', 3)->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->decimal('fee', 18, 4)->default(0);
            $table->decimal('balance', 18, 4)->nullable();
            $table->string('counterparty_phone', 20)->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('wallet_phone', 20)->nullable();
            $table->string('reference', 60)->nullable();
            $table->dateTime('occurred_at')->nullable();
            // confirmation
            $table->string('status', 12)->default('pending'); // unparsed|pending|confirmed|rejected|duplicate
            $table->string('target_type', 20)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fee_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('confirmed_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->index(['status', 'location_id']);
            $table->index(['provider', 'reference']);
        });
    }

    public function down(): void
    {
        foreach (['wallet_transactions', 'wallet_devices', 'wallet_message_templates', 'wallets'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
