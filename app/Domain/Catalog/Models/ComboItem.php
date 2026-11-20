<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComboItem extends Model
{
    protected $fillable = [
        'combo_variation_id',
        'component_variation_id',
        'unit_id',
        'qty',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Variation, $this> */
    public function comboVariation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'combo_variation_id');
    }

    /** @return BelongsTo<Variation, $this> */
    public function componentVariation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'component_variation_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }
}
