<?php

namespace App\Domain\Catalog\Models;

use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use Audited, SoftDeletes;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'allow_decimal' => false,
        'base_multiplier' => 1,
    ];

    protected $fillable = [
        'name',
        'short_name',
        'allow_decimal',
        'base_unit_id',
        'base_multiplier',
    ];

    protected function casts(): array
    {
        return [
            'allow_decimal' => 'boolean',
            'base_multiplier' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /** @return HasMany<Unit, $this> */
    public function subUnits(): HasMany
    {
        return $this->hasMany(Unit::class, 'base_unit_id');
    }
}
