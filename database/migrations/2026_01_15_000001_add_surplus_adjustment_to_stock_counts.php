<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A stock count posts two adjustments: the shortages (stock_adjustment_id) and the surpluses (this one). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->foreignId('surplus_adjustment_id')->nullable()->after('stock_adjustment_id')
                ->constrained('stock_adjustments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('surplus_adjustment_id');
        });
    }
};
