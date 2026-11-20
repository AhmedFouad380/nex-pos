<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StockAdjustmentItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'unit_multiplier' => 1,
        'unit_cost' => 0,
        'line_total' => 0,
    ];

    protected $fillable = [
        'stock_adjustment_id',
        'variation_id',
        'unit_id',
        'unit_multiplier',
        'qty',
        'base_qty',
        'unit_cost',
        'line_total',
        'lot_id',
        'lot_number',
        'exp_date',
    ];

    protected function casts(): array
    {
        return [
            'unit_multiplier' => 'decimal:4',
            'qty' => 'decimal:4',
            'base_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:4',
            'exp_date' => 'date',
        ];
    }

    /** @return BelongsTo<StockAdjustment, $this> */
    public function stockAdjustment(): BelongsTo
    {
        return $this->belongsTo(StockAdjustment::class, 'stock_adjustment_id');
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

    /** @return MorphMany<StockLayerConsumption, $this> */
    public function layerConsumptions(): MorphMany
    {
        return $this->morphMany(StockLayerConsumption::class, 'consumer');
    }
}
