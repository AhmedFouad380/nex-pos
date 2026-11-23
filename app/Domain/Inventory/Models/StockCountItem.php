<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variation;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockCountItem extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'expected_qty' => 0,
        'difference' => 0,
        'unit_cost' => 0,
    ];

    protected $fillable = [
        'stock_count_id',
        'variation_id',
        'lot_id',
        'expected_qty',
        'counted_qty',
        'difference',
        'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'expected_qty' => 'decimal:4',
            'counted_qty' => 'decimal:4',
            'difference' => 'decimal:4',
            'unit_cost' => 'decimal:4',
        ];
    }

    /** Records the quantity found on the shelf (null = not counted yet). */
    public function record(string|int|float|null $counted): void
    {
        $counted = $counted === null || $counted === '' ? null : Decimal::of($counted);
        $this->update([
            'counted_qty' => $counted === null ? null : Decimal::toDb($counted),
            'difference' => $counted === null ? 0 : Decimal::toDb($counted->minus(Decimal::of($this->expected_qty))),
        ]);
    }

    /** @return BelongsTo<StockCount, $this> */
    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
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
}
