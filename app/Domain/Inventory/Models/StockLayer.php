<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Foundation\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockLayer extends Model
{
    protected $fillable = [
        'location_id',
        'variation_id',
        'lot_id',
        'source_type',
        'source_id',
        'received_at',
        'exp_date',
        'original_qty',
        'remaining_qty',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'exp_date' => 'date',
            'original_qty' => 'decimal:4',
            'remaining_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /** @return BelongsTo<Lot, $this> */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(Lot::class, 'lot_id');
    }

    /** @return HasMany<StockLayerConsumption, $this> */
    public function consumptions(): HasMany
    {
        return $this->hasMany(StockLayerConsumption::class, 'stock_layer_id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo('source');
    }
}
