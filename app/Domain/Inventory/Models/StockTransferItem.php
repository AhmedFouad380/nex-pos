<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class StockTransferItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'unit_multiplier' => 1,
        'qty_received' => 0,
        'unit_cost' => 0,
    ];

    protected $fillable = [
        'stock_transfer_id',
        'variation_id',
        'unit_id',
        'unit_multiplier',
        'qty_sent',
        'qty_received',
        'base_qty',
        'unit_cost',
        'lot_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_multiplier' => 'decimal:4',
            'qty_sent' => 'decimal:4',
            'qty_received' => 'decimal:4',
            'base_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<StockTransfer, $this> */
    public function stockTransfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
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
