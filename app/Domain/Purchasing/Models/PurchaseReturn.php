<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Foundation\Models\Location;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseReturn extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'subtotal' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'paid_total' => 0,
        'due_total' => 0,
        'payment_status' => 'due',
    ];

    protected $fillable = [
        'number',
        'purchase_id',
        'supplier_id',
        'location_id',
        'return_date',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'paid_total',
        'due_total',
        'payment_status',
        'note',
        'created_by',
        'voided_at',
        'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'return_date' => 'datetime',
            'subtotal' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_total' => 'decimal:4',
            'due_total' => 'decimal:4',
            'payment_status' => PaymentStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
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

    /** @return HasMany<PurchaseReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class, 'purchase_return_id');
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
