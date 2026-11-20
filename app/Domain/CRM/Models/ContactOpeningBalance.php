<?php

namespace App\Domain\CRM\Models;

use App\Domain\CRM\Enums\BalanceDirection;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Foundation\Models\Location;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ContactOpeningBalance extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'paid_total' => 0,
        'due_total' => 0,
        'payment_status' => 'due',
    ];

    protected $fillable = [
        'contact_id',
        'location_id',
        'direction',
        'amount',
        'paid_total',
        'due_total',
        'payment_status',
        'as_of_date',
        'note',
        'created_by',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'direction' => BalanceDirection::class,
            'amount' => 'decimal:4',
            'paid_total' => 'decimal:4',
            'due_total' => 'decimal:4',
            'payment_status' => PaymentStatus::class,
            'as_of_date' => 'date',
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return MorphMany<PaymentAllocation, $this> */
    public function allocations(): MorphMany
    {
        return $this->morphMany(PaymentAllocation::class, 'allocatable');
    }

    /** @return MorphMany<JournalEntry, $this> */
    public function journalEntries(): MorphMany
    {
        return $this->morphMany(JournalEntry::class, 'source');
    }
}
