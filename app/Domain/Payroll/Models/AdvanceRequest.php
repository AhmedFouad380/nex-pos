<?php

namespace App\Domain\Payroll\Models;

use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Enums\AdvanceRequestStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An employee's request for an advance: pending → approved / rejected → paid (or cancelled by the employee). */
class AdvanceRequest extends Model
{
    use Audited;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'contact_id', 'location_id', 'amount', 'installment_amount', 'reason', 'status', 'requested_by',
        'approved_amount', 'approved_installment', 'decided_by', 'decided_at', 'decision_note', 'employee_advance_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => AdvanceRequestStatus::class,
            'amount' => 'decimal:4',
            'installment_amount' => 'decimal:4',
            'approved_amount' => 'decimal:4',
            'approved_installment' => 'decimal:4',
            'decided_at' => 'datetime',
        ];
    }

    /** The amount to pay: what the manager approved, else what was asked. */
    public function payableAmount(): string
    {
        return (string) ($this->approved_amount ?? $this->amount);
    }

    public function payableInstallment(): ?string
    {
        $value = $this->approved_amount !== null ? $this->approved_installment : $this->installment_amount;

        return $value === null ? null : (string) $value;
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** @return BelongsTo<EmployeeAdvance, $this> */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }
}
