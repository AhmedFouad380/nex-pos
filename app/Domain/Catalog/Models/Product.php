<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\BarcodeType;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Foundation\Models\Location;
use App\Domain\Foundation\Models\Note;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Shared\Enums\DurationUnit;
use App\Models\User;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use Audited, SoftDeletes;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'type' => 'single',
        'tax_type' => 'exclusive',
        'barcode_type' => 'C128',
        'track_stock' => true,
        'track_lots' => false,
        'track_expiry' => false,
        'track_serials' => false,
        'is_sellable' => true,
        'is_active' => true,
    ];

    protected $fillable = [
        'name',
        'sku',
        'type',
        'unit_id',
        'secondary_unit_id',
        'brand_id',
        'category_id',
        'tax_rate_id',
        'tax_type',
        'barcode_type',
        'track_stock',
        'alert_qty',
        'reorder_qty',
        'track_lots',
        'track_expiry',
        'expiry_period',
        'expiry_unit',
        'track_serials',
        'warranty_id',
        'weight',
        'is_sellable',
        'is_active',
        'preparation_minutes',
        'description',
        'custom_fields',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'tax_type' => TaxType::class,
            'barcode_type' => BarcodeType::class,
            'track_stock' => 'boolean',
            'alert_qty' => 'decimal:4',
            'reorder_qty' => 'decimal:4',
            'track_lots' => 'boolean',
            'track_expiry' => 'boolean',
            'expiry_unit' => DurationUnit::class,
            'track_serials' => 'boolean',
            'weight' => 'decimal:4',
            'is_sellable' => 'boolean',
            'is_active' => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /** @return BelongsTo<Unit, $this> */
    public function secondaryUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'secondary_unit_id');
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** @return BelongsTo<TaxRate, $this> */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class, 'tax_rate_id');
    }

    /** @return BelongsTo<Warranty, $this> */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class, 'warranty_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<Variation, $this> */
    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class, 'product_id');
    }

    /** @return HasMany<ProductAttribute, $this> */
    public function attributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class, 'product_id');
    }

    /** @return BelongsToMany<Location, $this> */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_product', 'product_id', 'location_id')
            ->withPivot('rack', 'row', 'position', 'is_featured');
    }

    /** @return BelongsToMany<Unit, $this> */
    public function units(): BelongsToMany
    {
        return $this->belongsToMany(Unit::class, 'product_unit', 'product_id', 'unit_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function modifiers(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_modifier_set', 'product_id', 'modifier_product_id');
    }

    /** @return MorphMany<Note, $this> */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    /** Services and combos have no stock of their own (combos move their components). */
    public function tracksStock(): bool
    {
        return $this->track_stock && ! in_array($this->type, [ProductType::Service, ProductType::Combo], true);
    }

    /** @return HasOne<Variation, $this> */
    public function defaultVariation(): HasOne
    {
        return $this->hasOne(Variation::class, 'product_id')->where('is_default', true);
    }

    /** @return HasManyThrough<StockLevel, Variation, $this> */
    public function stockLevels(): HasManyThrough
    {
        return $this->hasManyThrough(StockLevel::class, Variation::class, 'product_id', 'variation_id');
    }

    /** @return HasManyThrough<StockMovement, Variation, $this> */
    public function stockMovements(): HasManyThrough
    {
        return $this->hasManyThrough(StockMovement::class, Variation::class, 'product_id', 'variation_id');
    }
}
