<?php

namespace App\Domain\Payroll\Models;

use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Enums\EmployeeStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Salary data of an employee (a contact of type "employee"). */
class EmployeeProfile extends Model
{
    use Audited;

    protected $attributes = [
        'status' => 'active',
        'base_salary' => 0,
        'fixed_allowances' => 0,
    ];

    protected $fillable = [
        'contact_id',
        'user_id',
        'location_id',
        'job_title',
        'national_id',
        'hire_date',
        'end_date',
        'status',
        'base_salary',
        'fixed_allowances',
        'bank_account',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'end_date' => 'date',
            'status' => EmployeeStatus::class,
            'base_salary' => 'decimal:4',
            'fixed_allowances' => 'decimal:4',
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

    /**
     * The user account this employee signs in with (to request advances).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
