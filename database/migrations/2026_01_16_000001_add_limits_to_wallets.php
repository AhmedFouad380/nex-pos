<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Each wallet line: which SIM of the branch phone it is, and the provider's daily / monthly limits (null = none). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->unsignedTinyInteger('sim_slot')->nullable()->after('location_id');
            $table->decimal('daily_out_limit', 18, 4)->nullable()->after('sim_slot');
            $table->decimal('monthly_out_limit', 18, 4)->nullable()->after('daily_out_limit');
            $table->decimal('daily_in_limit', 18, 4)->nullable()->after('monthly_out_limit');
            $table->decimal('monthly_in_limit', 18, 4)->nullable()->after('daily_in_limit');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn(['sim_slot', 'daily_out_limit', 'monthly_out_limit', 'daily_in_limit', 'monthly_in_limit']);
        });
    }
};
