<?php

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Enums\PriceType;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\Variation;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Selling prices "as shown": tax-inclusive for inclusive products, exclusive otherwise.
 * A price group price replaces the default (fixed) or is a percentage of it (percent).
 */
final class PriceResolver
{
    /** Display price for one base unit × $multiplier units. */
    public function sellPrice(Variation $variation, ?int $priceGroupId = null, BigDecimal|string|int $multiplier = 1): BigDecimal
    {
        $product = $variation->product;
        $base = Decimal::of($product->tax_type === TaxType::Inclusive ? $variation->sell_price_inc_tax : $variation->sell_price);

        if ($priceGroupId) {
            $groupPrice = $variation->relationLoaded('prices')
                ? $variation->prices->firstWhere('price_group_id', $priceGroupId)
                : $variation->prices()->where('price_group_id', $priceGroupId)->first();
            if ($groupPrice) {
                $base = $groupPrice->price_type === PriceType::Percent
                    ? $base->multipliedBy(Decimal::of($groupPrice->value))->dividedBy(100, Decimal::SCALE, RoundingMode::HalfUp)
                    : Decimal::of($groupPrice->value);
            }
        }

        return $base->multipliedBy(Decimal::of($multiplier))->toScale(Decimal::SCALE, RoundingMode::HalfUp);
    }

    /** Converts a shown price to its tax-exclusive value (what the calculator works with). */
    public function exclusive(BigDecimal|string $shown, TaxType $taxType, BigDecimal|string $ratePercent): BigDecimal
    {
        $shown = Decimal::of($shown);
        if ($taxType !== TaxType::Inclusive || Decimal::of($ratePercent)->isZero()) {
            return $shown;
        }

        return $shown->multipliedBy(100)->dividedBy(Decimal::of($ratePercent)->plus(100), Decimal::SCALE, RoundingMode::HalfUp);
    }
}
