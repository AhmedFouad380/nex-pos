<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Data\DocumentTotals;
use App\Domain\Shared\Data\LineInput;
use App\Domain\Shared\Data\LineResult;
use App\Domain\Shared\Enums\DiscountType;
use App\Settings\GeneralSettings;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Pure arithmetic for sales/purchase documents (no DB):
 *   line:    price − line discount = unit price; + tax per unit; × qty = line total
 *   invoice: Σ lines − invoice discount + invoice tax (on the discounted subtotal) + charges = total
 * Unit values keep 4 decimals; line totals and document totals are rounded to the currency precision.
 */
final class DocumentCalculator
{
    public function __construct(private readonly GeneralSettings $settings) {}

    /** @param list<LineInput> $lines */
    public function calculate(
        array $lines,
        ?DiscountType $discountType = null,
        string|int|float|null $discountValue = null,
        string|int|float|null $invoiceTaxRate = null,
        string|int|float|null $charges = null,
    ): DocumentTotals {
        $results = array_map($this->line(...), $lines);

        $subtotal = array_reduce($results, fn (BigDecimal $c, LineResult $r) => $c->plus($r->lineTotal), BigDecimal::zero());
        $lineTaxes = array_reduce($results, fn (BigDecimal $c, LineResult $r) => $c->plus($r->lineTax), BigDecimal::zero());

        $discount = $this->money(BigDecimal::min($subtotal, $this->discount($subtotal, $discountType, Decimal::of($discountValue))));
        $invoiceTax = $this->money($subtotal->minus($discount)->multipliedBy(Decimal::of($invoiceTaxRate))->dividedBy(100, 8, RoundingMode::HalfUp));
        $charges = $this->money(Decimal::of($charges));

        return new DocumentTotals(
            lines: $results,
            subtotal: $subtotal,
            discountAmount: $discount,
            invoiceTax: $invoiceTax,
            taxAmount: $this->money($lineTaxes)->plus($invoiceTax),
            charges: $charges,
            total: $subtotal->minus($discount)->plus($invoiceTax)->plus($charges),
        );
    }

    public function line(LineInput $line): LineResult
    {
        $qty = Decimal::of($line->qty);
        $price = Decimal::of($line->price);
        $unitPrice = BigDecimal::max(BigDecimal::zero(), $price->minus($this->discount($price, $line->discountType, Decimal::of($line->discountValue))))
            ->toScale(Decimal::SCALE, RoundingMode::HalfUp);
        $taxPerUnit = $unitPrice->multipliedBy(Decimal::of($line->taxRate))->dividedBy(100, Decimal::SCALE, RoundingMode::HalfUp);
        $incTax = $unitPrice->plus($taxPerUnit);

        return new LineResult(
            unitPrice: $unitPrice,
            taxPerUnit: $taxPerUnit,
            unitPriceIncTax: $incTax,
            lineTotal: $this->money($qty->multipliedBy($incTax)),
            lineTax: $qty->multipliedBy($taxPerUnit)->toScale(Decimal::SCALE, RoundingMode::HalfUp),
            lineNet: $qty->multipliedBy($unitPrice)->toScale(Decimal::SCALE, RoundingMode::HalfUp),
        );
    }

    /** Rounds to the currency precision but keeps the DB scale (e.g. 12.3400). */
    public function money(BigDecimal $amount): BigDecimal
    {
        return $amount->toScale($this->settings->currency_precision, RoundingMode::HalfUp)->toScale(Decimal::SCALE);
    }

    private function discount(BigDecimal $base, ?DiscountType $type, BigDecimal $value): BigDecimal
    {
        return match ($type) {
            DiscountType::Percent => $base->multipliedBy(BigDecimal::min($value, BigDecimal::of(100)))->dividedBy(100, 8, RoundingMode::HalfUp),
            DiscountType::Fixed => $value,
            null => BigDecimal::zero(),
        };
    }
}
