<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Currency;
use App\Domain\Foundation\Models\Location;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Shared\Enums\DiscountType;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'draft',
        'exchange_rate' => 1,
        'subtotal' => 0,
        'discount_value' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'shipping_charges' => 0,
        'total' => 0,
    ];

    protected $fillable = [
        'number',
        'supplier_id',
        'location_id',
        'purchase_requisition_id',
        'order_date',
        'expected_at',
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
        'total',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'expected_at' => 'date',
            'status' => PurchaseOrderStatus::class,
            'exchange_rate' => 'decimal:6',
            'subtotal' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'discount_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'shipping_charges' => 'decimal:4',
            'total' => 'decimal:4',
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

    /** @return BelongsTo<PurchaseRequisition, $this> */
    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
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

    /** @return HasMany<PurchaseOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'purchase_order_id');
    }
}
