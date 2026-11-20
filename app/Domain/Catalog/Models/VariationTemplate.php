<?php

namespace App\Domain\Catalog\Models;

use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariationTemplate extends Model
{
    use Audited;

    protected $fillable = [
        'name',
    ];

    /** @return HasMany<VariationTemplateValue, $this> */
    public function values(): HasMany
    {
        return $this->hasMany(VariationTemplateValue::class, 'variation_template_id');
    }
}
