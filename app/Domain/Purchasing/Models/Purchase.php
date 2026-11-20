<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Foundation\Models\Currency;
use App\Domain\Foundation\Models\Location;
use App\Domain\Foundation\Models\Note;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Shared\Enums\DiscountType;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Purchase extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'received',
        'exchange_rate' => 1,
        'subtotal' => 0,
        'discount_value' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'shipping_charges' => 0,
        'additional_costs_total' => 0,
        'total' => 0,
        'paid_total' => 0,
        'due_total' => 0,
        'payment_status' => 'due',
    ];

    protected $fillable = [
        'number',
        'supplier_ref',
        'supplier_id',
        'location_id',
        'purchase_order_id',
        'purchase_date',
        'due_date',
        'status',
        'currency_id',
        'exchange_rate',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate_id',
        'tax_amount',
        'shipping_charges',
        'shipping_details',
        'additional_costs_total',
        'total',
        'paid_total',
        'due_total',
        'payment_status',
        'note',
        'import_batch',
        'custom_fields',
        'created_by',
        'updated_by',
        'voided_at',
        'void_reason',
        'voided_by',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'datetime',
            'due_date' => 'date',
            'status' => PurchaseStatus::class,
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'shipping_charges' => 'decimal:4',
            'additional_costs_total' => 'decimal:4',
            'total' => 'decimal:4',
            'paid_total' => 'decimal:4',
            'due_total' => 'decimal:4',
            'payment_status' => PaymentStatus::class,
            'custom_fields' => 'array',
            'voided_at' => 'datetime',
        ];
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

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    /** @return BelongsTo<Currency, $this> */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** @return HasMany<PurchaseItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class, 'purchase_id');
    }

    /** @return HasMany<PurchaseCost, $this> */
    public function costs(): HasMany
    {
        return $this->hasMany(PurchaseCost::class, 'purchase_id');
    }

    /** @return HasMany<PurchaseReturn, $this> */
    public function returns(): HasMany
    {
        return $this->hasMany(PurchaseReturn::class, 'purchase_id');
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

    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }
}
