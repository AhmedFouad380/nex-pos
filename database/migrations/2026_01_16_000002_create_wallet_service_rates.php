<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The wallet service the branches sell: a customer pays cash and the shop sends it from a wallet (transfer), or sends to
 * the shop's wallet and takes cash (withdrawal). Each branch sets its commission: a percentage with a minimum.
 * A confirmed service operation keeps its commission, the shift its cash went into, and the commission voucher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_service_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('transfer_percent', 8, 4)->default(0);
            $table->decimal('transfer_min', 18, 4)->default(0);
            $table->decimal('withdraw_percent', 8, 4)->default(0);
            $table->decimal('withdraw_min', 18, 4)->default(0);
            $table->timestamps();
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->decimal('commission', 18, 4)->nullable()->after('fee');
            $table->foreignId('cash_session_id')->nullable()->after('expense_id')->constrained()->nullOnDelete();
            $table->foreignId('commission_payment_id')->nullable()->after('cash_session_id')->constrained('payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('commission_payment_id');
            $table->dropConstrainedForeignId('cash_session_id');
            $table->dropColumn('commission');
        });
        Schema::dropIfExists('wallet_service_rates');
    }
};
