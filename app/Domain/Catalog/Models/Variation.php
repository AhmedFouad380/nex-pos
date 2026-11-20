<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Inventory\Models\Lot;
use App\Domain\Inventory\Models\Serial;
use App\Domain\Inventory\Models\StockLayer;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Support\Audit\Audited;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Variation extends Model
{
    use Audited, SoftDeletes;

    /** Mirrors DB defaults so freshly created models are complete (strict mode). */
    protected $attributes = [
        'name' => 'DEFAULT',
        'purchase_price' => 0,
        'purchase_price_inc_tax' => 0,
        'profit_margin' => 0,
        'sell_price' => 0,
        'sell_price_inc_tax' => 0,
        'is_default' => false,
        'sort' => 0,
    ];

    protected $fillable = [
        'product_id',
        'name',
        'attribute_values',
        'sku',
        'purchase_price',
        'purchase_price_inc_tax',
        'profit_margin',
        'sell_price',
        'sell_price_inc_tax',
        'is_default',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'attribute_values' => 'array',
            'purchase_price' => 'decimal:4',
            'purchase_price_inc_tax' => 'decimal:4',
            'profit_margin' => 'decimal:4',
            'sell_price' => 'decimal:4',
            'sell_price_inc_tax' => 'decimal:4',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return HasMany<VariationBarcode, $this> */
    public function barcodes(): HasMany
    {
        return $this->hasMany(VariationBarcode::class, 'variation_id');
    }

    /** @return HasMany<VariationPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(VariationPrice::class, 'variation_id');
    }

    /** @return HasMany<ComboItem, $this> */
    public function comboItems(): HasMany
    {
        return $this->hasMany(ComboItem::class, 'combo_variation_id');
    }

    /** @return HasMany<StockLevel, $this> */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class, 'variation_id');
    }

    /** @return HasMany<StockMovement, $this> */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'variation_id');
    }

    /** @return HasMany<StockLayer, $this> */
    public function stockLayers(): HasMany
    {
        return $this->hasMany(StockLayer::class, 'variation_id');
    }

    /** @return HasMany<Lot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(Lot::class, 'variation_id');
    }

    /** @return HasMany<Serial, $this> */
    public function serials(): HasMany
    {
        return $this->hasMany(Serial::class, 'variation_id');
    }

    /** "Product" or "Product — Size / Color" for variable products. */
    public function fullName(): string
    {
        $product = $this->product;

        return $this->name === 'DEFAULT' || $product?->type === ProductType::Single
            ? (string) $product?->name
            : "{$product?->name} — {$this->name}";
    }
}
