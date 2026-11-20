<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Foundation\Models\Location;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    public const CREATED_AT = null;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'qty_on_hand' => 0,
        'qty_reserved' => 0,
        'avg_cost' => 0,
    ];

    protected $fillable = [
        'location_id',
        'variation_id',
        'qty_on_hand',
        'qty_reserved',
        'avg_cost',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'decimal:4',
            'qty_reserved' => 'decimal:4',
            'avg_cost' => 'decimal:4',
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
}
