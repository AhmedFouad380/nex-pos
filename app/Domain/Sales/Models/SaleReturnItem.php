<?php

namespace App\Domain\Sales\Models;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Inventory\Models\Lot;
use App\Domain\Inventory\Models\StockLayer;
use App\Domain\Inventory\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class SaleReturnItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'unit_multiplier' => 1,
        'unit_price' => 0,
        'tax_amount' => 0,
        'line_total' => 0,
        'unit_cost' => 0,
        'restock' => true,
    ];

    protected $fillable = [
        'sale_return_id',
        'sale_item_id',
        'variation_id',
        'unit_id',
        'unit_multiplier',
        'qty',
        'base_qty',
        'unit_price',
        'tax_amount',
        'line_total',
        'unit_cost',
        'restock',
        'lot_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_multiplier' => 'decimal:4',
            'qty' => 'decimal:4',
            'base_qty' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'line_total' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'restock' => 'boolean',
        ];
    }

    /** @return BelongsTo<SaleReturn, $this> */
    public function saleReturn(): BelongsTo
    {
        return $this->belongsTo(SaleReturn::class, 'sale_return_id');
    }

    /** @return BelongsTo<SaleItem, $this> */
    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
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

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
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
