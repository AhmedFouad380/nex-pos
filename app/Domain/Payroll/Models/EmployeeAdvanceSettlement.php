<?php

namespace App\Domain\Payroll\Models;

use App\Domain\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One repayment of an advance: a payroll deduction (payroll_line_id) or cash handed back (payment_id). */
class EmployeeAdvanceSettlement extends Model
{
    protected $fillable = [
        'employee_advance_id',
        'payroll_line_id',
        'payment_id',
        'amount',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'settled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EmployeeAdvance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    /** @return BelongsTo<PayrollLine, $this> */
    public function payrollLine(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }
}
