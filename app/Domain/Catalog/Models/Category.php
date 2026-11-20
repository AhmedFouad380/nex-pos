<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Restaurant\Models\KitchenStation;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Category extends Model
{
    use Audited, SoftDeletes;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'sort' => 0,
    ];

    protected $fillable = [
        'parent_id',
        'name',
        'code',
        'description',
        'sort',
    ];

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /** @return BelongsToMany<KitchenStation, $this> */
    public function kitchenStations(): BelongsToMany
    {
        return $this->belongsToMany(KitchenStation::class, 'category_kitchen_station', 'category_id', 'kitchen_station_id');
    }
}
