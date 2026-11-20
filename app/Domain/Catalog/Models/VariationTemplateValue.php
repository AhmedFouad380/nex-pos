<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VariationTemplateValue extends Model
{
    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'sort' => 0,
    ];

    protected $fillable = [
        'variation_template_id',
        'value',
        'sort',
    ];

    /** @return BelongsTo<VariationTemplate, $this> */
    public function variationTemplate(): BelongsTo
    {
        return $this->belongsTo(VariationTemplate::class, 'variation_template_id');
    }
}
