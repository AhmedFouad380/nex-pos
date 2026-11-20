<?php

namespace App\Domain\Shared\Data;

use Brick\Math\BigDecimal;

final readonly class LineResult
{
    public function __construct(
        public BigDecimal $unitPrice,      // after line discount, excl. tax
        public BigDecimal $taxPerUnit,
        public BigDecimal $unitPriceIncTax,
        public BigDecimal $lineTotal,      // qty × unit price incl. tax, rounded to currency precision
        public BigDecimal $lineTax,        // qty × tax per unit
        public BigDecimal $lineNet,        // qty × unit price excl. tax
    ) {}
}
