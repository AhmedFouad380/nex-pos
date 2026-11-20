<?php

namespace App\Domain\Catalog\Models;

use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxRate extends Model
{
    use Audited, SoftDeletes;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'rate' => 0,
        'is_group' => false,
        'for_group_only' => false,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'rate',
        'is_group',
        'for_group_only',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:4',
            'is_group' => 'boolean',
            'for_group_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<TaxRate, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(TaxRate::class, 'tax_rate_group', 'group_id', 'tax_rate_id');
    }

    /** @return BelongsToMany<TaxRate, $this> */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(TaxRate::class, 'tax_rate_group', 'tax_rate_id', 'group_id');
    }
}
