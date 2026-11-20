<?php

namespace App\Domain\Purchasing\Models;

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Inventory\Models\Lot;
use App\Domain\Inventory\Models\Serial;
use App\Domain\Inventory\Models\StockLayer;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Shared\Enums\DiscountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'unit_multiplier' => 1,
        'unit_cost_before_discount' => 0,
        'discount_value' => 0,
        'unit_cost' => 0,
        'tax_amount' => 0,
        'unit_cost_inc_tax' => 0,
        'landed_unit_cost' => 0,
        'line_total' => 0,
        'qty_returned' => 0,
    ];

    protected $fillable = [
        'purchase_id',
        'purchase_order_item_id',
        'variation_id',
        'unit_id',
        'unit_multiplier',
        'qty',
        'base_qty',
        'secondary_qty',
        'unit_cost_before_discount',
        'discount_type',
        'discount_value',
        'unit_cost',
        'tax_rate_id',
        'tax_amount',
        'unit_cost_inc_tax',
        'landed_unit_cost',
        'line_total',
        'qty_returned',
        'lot_number',
        'lot_id',
        'mfg_date',
        'exp_date',
        'new_sell_price',
    ];

    protected function casts(): array
    {
        return [
            'unit_multiplier' => 'decimal:4',
            'qty' => 'decimal:4',
            'base_qty' => 'decimal:4',
            'secondary_qty' => 'decimal:4',
            'unit_cost_before_discount' => 'decimal:4',
            'discount_type' => DiscountType::class,
            'discount_value' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'unit_cost_inc_tax' => 'decimal:4',
            'landed_unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
            'qty_returned' => 'decimal:4',
            'mfg_date' => 'date',
            'exp_date' => 'date',
            'new_sell_price' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    /** @return BelongsTo<PurchaseOrderItem, $this> */
    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
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

    /** @return HasMany<Serial, $this> */
    public function serials(): HasMany
    {
        return $this->hasMany(Serial::class, 'purchase_item_id');
    }

    /** @return MorphMany<StockMovement, $this> */
    public function stockMovements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }

    /** @return MorphMany<StockLayer, $this> */
    public function stockLayers(): MorphMany
    {
        return $this->morphMany(StockLayer::class, 'source');
    }
}
