<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\Discount;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Models\Warranty;
use App\Domain\Inventory\Models\Lot;
use App\Domain\Inventory\Models\Serial;
use App\Domain\Inventory\Models\StockLayerConsumption;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Restaurant\Enums\KitchenStatus;
use App\Domain\Restaurant\Models\KitchenStation;
use App\Domain\Sales\Enums\SaleItemChildType;
use App\Domain\Shared\Enums\DiscountType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SaleItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'unit_multiplier' => 1,
        'unit_price_before_discount' => 0,
        'discount_value' => 0,
        'unit_price' => 0,
        'tax_amount' => 0,
        'unit_price_inc_tax' => 0,
        'line_total' => 0,
        'unit_cost' => 0,
        'cost_total' => 0,
        'qty_returned' => 0,
    ];

    protected $fillable = [
        'sale_id',
        'parent_item_id',
        'child_type',
        'sales_order_item_id',
        'product_id',
        'variation_id',
        'unit_id',
        'unit_multiplier',
        'qty',
        'base_qty',
        'secondary_qty',
        'unit_price_before_discount',
        'discount_type',
        'discount_value',
        'discount_id',
        'unit_price',
        'tax_rate_id',
        'tax_amount',
        'unit_price_inc_tax',
        'line_total',
        'unit_cost',
        'cost_total',
        'qty_returned',
        'lot_id',
        'warranty_id',
        'service_staff_id',
        'kitchen_station_id',
        'kitchen_status',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'child_type' => SaleItemChildType::class,
            'unit_multiplier' => 'decimal:4',
            'qty' => 'decimal:4',
            'base_qty' => 'decimal:4',
            'secondary_qty' => 'decimal:4',
            'unit_price_before_discount' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'unit_price_inc_tax' => 'decimal:4',
            'line_total' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'cost_total' => 'decimal:4',
            'qty_returned' => 'decimal:4',
            'kitchen_status' => KitchenStatus::class,
        ];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'parent_item_id');
    }

    /** @return BelongsTo<SalesOrderItem, $this> */
    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(SalesOrderItem::class, 'sales_order_item_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /** @return BelongsTo<Discount, $this> */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class, 'discount_id');
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    /** @return BelongsTo<Warranty, $this> */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_id');
    }

    /** @return BelongsTo<User, $this> */
    public function serviceStaff(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_staff_id');
    }

    /** @return BelongsTo<KitchenStation, $this> */
    public function kitchenStation(): BelongsTo
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    /** @return HasMany<SaleItem, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'parent_item_id');
    }

    /** @return HasMany<SaleReturnItem, $this> */
    public function returnItems(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class, 'sale_item_id');
    }

    /** @return BelongsToMany<Serial, $this> */
    public function serials(): BelongsToMany
    {
        return $this->belongsToMany(Serial::class, 'sale_item_serials', 'sale_item_id', 'serial_id');
    }

    /** @return MorphMany<StockMovement, $this> */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }

    /** @return MorphMany<StockLayerConsumption, $this> */
    public function layerConsumptions(): MorphMany
    {
        return $this->morphMany(StockLayerConsumption::class, 'consumer');
    }
}
