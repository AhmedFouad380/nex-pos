<?php

namespace App\Domain\Purchasing\Services;

use App\Domain\Purchasing\Enums\CostDistribution;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Spreads invoice-level amounts over the lines of a purchase:
 *   - the invoice discount lowers each line's cost by its share of value,
 *   - shipping and each additional cost raise it by value / quantity / weight.
 * Returns the landed cost per BASE unit, in base currency, per line.
 */
final class LandedCostAllocator
{
    /**
     * @param  list<array{net: BigDecimal, base_qty: BigDecimal, weight: BigDecimal}>  $lines  net = line value excl. tax (doc currency)
     * @param  list<array{amount: BigDecimal, by: CostDistribution}>  $additions
     * @return list<BigDecimal>
     */
    public function allocate(array $lines, BigDecimal $discount, array $additions, BigDecimal $exchangeRate): array
    {
        $totalNet = $this->sum(array_column($lines, 'net'));
        $results = [];

        foreach ($lines as $i => $line) {
            $value = $line['net']->minus($this->share($discount, $line['net'], $totalNet));

            foreach ($additions as $addition) {
                [$weight, $total] = match ($addition['by']) {
                    CostDistribution::Qty => [$line['base_qty'], $this->sum(array_column($lines, 'base_qty'))],
                    CostDistribution::Weight => [$line['weight'], $this->sum(array_column($lines, 'weight'))],
                    CostDistribution::Value => [$line['net'], $totalNet],
                };
                if (! $total->isPositive()) { // e.g. no weights entered → fall back to value
                    [$weight, $total] = [$line['net'], $totalNet];
                }
                $value = $value->plus($this->share($addition['amount'], $weight, $total));
            }

            $results[$i] = $line['base_qty']->isPositive()
                ? $value->multipliedBy($exchangeRate)->dividedBy($line['base_qty'], Decimal::SCALE, RoundingMode::HalfUp)
                : BigDecimal::zero();
        }

        return $results;
    }

    private function share(BigDecimal $amount, BigDecimal $part, BigDecimal $whole): BigDecimal
    {
        return $whole->isPositive() ? $amount->multipliedBy($part)->dividedBy($whole, 8, RoundingMode::HalfUp) : BigDecimal::zero();
    }

    /** @param list<BigDecimal> $values */
    private function sum(array $values): BigDecimal
    {
        return array_reduce($values, fn (BigDecimal $c, BigDecimal $v) => $c->plus($v), BigDecimal::zero());
    }
}
