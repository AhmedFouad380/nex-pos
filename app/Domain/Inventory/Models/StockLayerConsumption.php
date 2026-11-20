<?php

namespace App\Domain\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockLayerConsumption extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'stock_layer_id',
        'consumer_type',
        'consumer_id',
        'qty',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<StockLayer, $this> */
    public function stockLayer(): BelongsTo
    {
        return $this->belongsTo(StockLayer::class, 'stock_layer_id');
    }

    /** @return MorphTo<Model, $this> */
    public function consumer(): MorphTo
    {
        return $this->morphTo('consumer');
    }
}
