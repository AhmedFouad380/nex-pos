<?php

namespace App\Domain\Catalog\Models;

use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'sort' => 0,
    ];

    protected $fillable = [
        'product_id',
        'variation_template_id',
        'name',
        'sort',
    ];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<VariationTemplate, $this> */
    public function variationTemplate(): BelongsTo
    {
        return $this->belongsTo(VariationTemplate::class, 'variation_template_id');
    }
}
