<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * استلام نقدية الوردية: once a shift is closed, a manager / the accountant takes its cash out of the branch drawer
 * into another box, leaving a float behind. The move itself is an account transfer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dateTime('received_at')->nullable()->after('closing_note');
            $table->foreignId('received_by')->nullable()->after('received_at')->constrained('users')->nullOnDelete();
            $table->decimal('received_amount', 18, 4)->nullable()->after('received_by');
            $table->decimal('kept_float', 18, 4)->nullable()->after('received_amount');
            $table->foreignId('received_account_id')->nullable()->after('kept_float')->constrained('accounts')->nullOnDelete();
            $table->foreignId('account_transfer_id')->nullable()->after('received_account_id')->constrained('account_transfers')->nullOnDelete();
            $table->text('received_note')->nullable()->after('account_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropConstrainedForeignId('received_account_id');
            $table->dropConstrainedForeignId('account_transfer_id');
            $table->dropColumn(['received_at', 'received_amount', 'kept_float', 'received_note']);
        });
    }
};
