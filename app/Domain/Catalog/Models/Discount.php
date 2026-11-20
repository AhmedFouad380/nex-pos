<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Foundation\Models\Location;
use App\Domain\Shared\Enums\DiscountType;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Discount extends Model
{
    use Audited;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'type' => 'percent',
        'priority' => 0,
        'applies_with_customer_group' => false,
        'applies_with_price_group' => false,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'type',
        'value',
        'priority',
        'starts_at',
        'ends_at',
        'location_id',
        'price_group_id',
        'applies_with_customer_group',
        'applies_with_price_group',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => DiscountType::class,
            'value' => 'decimal:4',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'applies_with_customer_group' => 'boolean',
            'applies_with_price_group' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /** @return BelongsTo<PriceGroup, $this> */
    public function priceGroup(): BelongsTo
    {
        return $this->belongsTo(PriceGroup::class, 'price_group_id');
    }

    /** @return MorphToMany<Variation, $this> */
    public function variations(): MorphToMany
    {
        return $this->morphedByMany(Variation::class, 'discountable', 'discountables');
    }

    /** @return MorphToMany<Product, $this> */
    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'discountable', 'discountables');
    }

    /** @return MorphToMany<Brand, $this> */
    public function brands(): MorphToMany
    {
        return $this->morphedByMany(Brand::class, 'discountable', 'discountables');
    }

    /** @return MorphToMany<Category, $this> */
    public function categories(): MorphToMany
    {
        return $this->morphedByMany(Category::class, 'discountable', 'discountables');
    }
}
