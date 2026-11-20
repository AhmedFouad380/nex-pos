<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Catalog\Models\Variation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lot extends Model
{
    protected $fillable = [
        'variation_id',
        'number',
        'mfg_date',
        'exp_date',
    ];

    protected function casts(): array
    {
        return [
            'mfg_date' => 'date',
            'exp_date' => 'date',
        ];
    }

    /** @return BelongsTo<Variation, $this> */
    public function variation(): BelongsTo
    {
        return $this->belongsTo(Variation::class, 'variation_id');
    }
}
