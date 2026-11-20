<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\PriceType;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariationPrice extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'price_type' => 'fixed',
    ];

    protected $fillable = [
        'variation_id',
        'price_group_id',
        'price_type',
        'value',
    ];

    protected function casts(): array
    {
        return [
            'price_type' => PriceType::class,
            'value' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }

    /** @return BelongsTo<PriceGroup, $this> */
    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class, 'price_group_id');
    }
}
