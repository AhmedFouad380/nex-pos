<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\InvalidUnit;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Support\Decimal;
use Brick\Math\BigDecimal;

/** Converts between a product's base unit and its sub-units (e.g. carton = 12 pieces). */
final class UnitConverter
{
    /** How many base units one $unit holds for this product. */
    public function multiplier(Product $product, Unit $unit): BigDecimal
    {
        if ($unit->id === $product->unit_id) {
            return BigDecimal::one();
        }

        if ($unit->base_unit_id === $product->unit_id) {
            return Decimal::of($unit->base_multiplier);
        }

        throw InvalidUnit::for($product, $unit);
    }

    /** @return array<int, string> unit id => name, for selects: base unit + its sub-units allowed on the product */
    public function options(Product $product): array
    {
        $allowed = $product->units()->pluck('units.id');

        return Unit::query()
            ->whereKey($product->unit_id)
            ->orWhere(fn ($q) => $q->where('base_unit_id', $product->unit_id)
                ->when($allowed->isNotEmpty(), fn ($q) => $q->whereIn('id', $allowed)))
            ->orderByRaw('base_unit_id is not null')
            ->pluck('name', 'id')
            ->all();
    }
}
