<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employees' salary data, advances (سلف) and monthly payroll runs (مسيرات الرواتب).
 * An employee is a contact of type "employee"; their account (EmployeeAccounts, tagged with the contact)
 * is debited by advances and payouts and credited by earned salaries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('job_title')->nullable();
            $table->string('national_id', 30)->nullable();
            $table->date('hire_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 12)->default('active'); // active|suspended|terminated
            $table->decimal('base_salary', 18, 4)->default(0);       // monthly
            $table->decimal('fixed_allowances', 18, 4)->default(0);  // monthly
            $table->string('bank_account', 60)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        // The money itself goes out as a payment voucher (payment_id); this row tracks what is still owed back.
        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->dateTime('advance_date');
            $table->decimal('amount', 18, 4);
            $table->decimal('installment_amount', 18, 4)->nullable(); // monthly deduction; null = all of it next payroll
            $table->decimal('settled_total', 18, 4)->default(0);
            $table->decimal('remaining', 18, 4)->default(0);
            $table->string('status', 10)->default('open'); // open|settled
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['contact_id', 'status']);
        });

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->date('period');        // first day of the month
            $table->date('posting_date');  // date of the accrual entry (usually the month's last day)
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete(); // null = all branches
            $table->string('status', 16)->default('draft'); // draft|approved|partially_paid|paid
            $table->decimal('total_earned', 18, 4)->default(0);
            $table->decimal('total_advances', 18, 4)->default(0);
            $table->decimal('total_net', 18, 4)->default(0);
            $table->decimal('total_paid', 18, 4)->default(0);
            $table->text('note')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();
            $table->index(['period', 'status']);
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->decimal('base_salary', 18, 4)->default(0);
            $table->decimal('allowances', 18, 4)->default(0);
            $table->decimal('overtime', 18, 4)->default(0);
            $table->decimal('bonus', 18, 4)->default(0);
            $table->decimal('absence_days', 6, 2)->default(0);
            $table->decimal('absence_deduction', 18, 4)->default(0);
            $table->decimal('penalties', 18, 4)->default(0);
            $table->decimal('other_deductions', 18, 4)->default(0);
            $table->decimal('earned', 18, 4)->default(0);            // what the month cost: posted to salaries expense
            $table->decimal('advance_deduction', 18, 4)->default(0); // kept back to repay advances
            $table->decimal('net', 18, 4)->default(0);               // what is handed over
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'contact_id']);
            $table->index('contact_id');
        });

        // Each repayment of an advance: a deduction on an approved payroll line, or cash handed back (payment).
        Schema::create('employee_advance_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_advance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 4);
            $table->dateTime('settled_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['employee_advance_settlements', 'payroll_lines', 'payroll_runs', 'employee_advances', 'employee_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
