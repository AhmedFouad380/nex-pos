<?php

namespace App\Domain\Sales\Models;

use App\Domain\CashManagement\Models\CashSession;
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

class SaleReturn extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'subtotal' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'total' => 0,
        'refunded_total' => 0,
        'due_total' => 0,
        'payment_status' => 'due',
        'cost_total' => 0,
    ];

    protected $fillable = [
        'number',
        'sale_id',
        'customer_id',
        'location_id',
        'cash_session_id',
        'return_date',
        'subtotal',
        'discount_amount',
        'tax_amount',
        'total',
        'refunded_total',
        'due_total',
        'payment_status',
        'cost_total',
        'reason',
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
            'refunded_total' => 'decimal:4',
            'due_total' => 'decimal:4',
            'payment_status' => PaymentStatus::class,
            'cost_total' => 'decimal:4',
            'voided_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    /** @return BelongsTo<Contact, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<CashSession, $this> */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class, 'cash_session_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<SaleReturnItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'sale_return_id');
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
