<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبات السلف: an employee asks for an advance from their own account, a manager approves (or rejects) it,
 * then whoever pays advances gives it — only then the payment voucher and the advance exist.
 * employee_profiles.user_id links an employee to the user account they sign in with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('contact_id')->constrained()->nullOnDelete();
        });

        Schema::create('advance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 18, 4);
            $table->decimal('installment_amount', 18, 4)->nullable();
            $table->string('reason')->nullable();
            $table->string('status', 12)->default('pending'); // pending|approved|rejected|paid|cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('approved_amount', 18, 4)->nullable();
            $table->decimal('approved_installment', 18, 4)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note')->nullable();
            $table->foreignId('employee_advance_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advance_requests');
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
