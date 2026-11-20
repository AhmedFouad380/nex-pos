<?php

namespace App\Domain\Payroll\Models;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Models\Payment;
use App\Support\Audit\Audited;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One employee's salary in a payroll run. */
class PayrollLine extends Model
{
    use Audited;

    /** Amount columns an editor may change on a draft line. */
    public const EDITABLE = ['base_salary', 'allowances', 'overtime', 'bonus', 'absence_days', 'absence_deduction',
        'penalties', 'other_deductions', 'advance_deduction'];

    protected $attributes = [
        'base_salary' => 0,
        'allowances' => 0,
        'overtime' => 0,
        'bonus' => 0,
        'absence_days' => 0,
        'absence_deduction' => 0,
        'penalties' => 0,
        'other_deductions' => 0,
        'earned' => 0,
        'advance_deduction' => 0,
        'net' => 0,
    ];

    protected $fillable = [
        'payroll_run_id',
        'contact_id',
        'base_salary',
        'allowances',
        'overtime',
        'bonus',
        'absence_days',
        'absence_deduction',
        'penalties',
        'other_deductions',
        'earned',
        'advance_deduction',
        'net',
        'payment_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'base_salary' => 'decimal:4',
            'allowances' => 'decimal:4',
            'overtime' => 'decimal:4',
            'bonus' => 'decimal:4',
            'absence_days' => 'decimal:2',
            'absence_deduction' => 'decimal:4',
            'penalties' => 'decimal:4',
            'other_deductions' => 'decimal:4',
            'earned' => 'decimal:4',
            'advance_deduction' => 'decimal:4',
            'net' => 'decimal:4',
        ];
    }

    /** Handed over: has a live payout voucher, or there was nothing to hand over. */
    public function isPaid(): bool
    {
        return $this->payment_id !== null || ! Decimal::of($this->net)->isPositive();
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /** @return HasMany<EmployeeAdvanceSettlement, $this> */
    public function advanceSettlements(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceSettlement::class, 'payroll_line_id');
    }
}
