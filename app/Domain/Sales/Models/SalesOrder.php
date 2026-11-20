<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Location;
use App\Domain\Sales\Enums\SalesOrderStatus;
use App\Domain\Shared\Enums\DiscountType;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesOrder extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'status' => 'open',
        'subtotal' => 0,
        'discount_value' => 0,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'shipping_charges' => 0,
        'total' => 0,
    ];

    protected $fillable = [
        'number',
        'customer_id',
        'location_id',
        'order_date',
        'delivery_date',
        'status',
        'price_group_id',
        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate_id',
        'tax_amount',
        'shipping_charges',
        'total',
        'shipping_address',
        'note',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'datetime',
            'delivery_date' => 'date',
            'status' => SalesOrderStatus::class,
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
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<PriceGroup, $this> */
    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class, 'price_group_id');
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

    /** @return HasMany<SalesOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class, 'sales_order_id');
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class, 'sales_order_id');
    }
}
