<?php

namespace App\Domain\Payroll\Models;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Enums\AdvanceStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An advance (سلفة) paid to an employee, repaid by payroll deductions or cash. */
class EmployeeAdvance extends Model
{
    use Audited;

    protected $attributes = [
        'settled_total' => 0,
        'remaining' => 0,
        'status' => 'open',
    ];

    protected $fillable = [
        'number',
        'contact_id',
        'location_id',
        'payment_id',
        'advance_date',
        'amount',
        'installment_amount',
        'settled_total',
        'remaining',
        'status',
        'note',
        'created_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'advance_date' => 'datetime',
            'amount' => 'decimal:4',
            'installment_amount' => 'decimal:4',
            'settled_total' => 'decimal:4',
            'remaining' => 'decimal:4',
            'status' => AdvanceStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /** @return HasMany<EmployeeAdvanceSettlement, $this> */
    public function settlements(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceSettlement::class, 'employee_advance_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
