<?php

namespace App\Domain\Shared\Data;

use Brick\Math\BigDecimal;

final readonly class DocumentTotals
{
    /** @param list<LineResult> $lines */
    public function __construct(
        public array $lines,
        public BigDecimal $subtotal,       // Σ line totals (incl. line taxes)
        public BigDecimal $discountAmount, // invoice-level discount
        public BigDecimal $invoiceTax,     // tax applied on (subtotal − discount)
        public BigDecimal $taxAmount,      // line taxes + invoice tax (for reports)
        public BigDecimal $charges,        // shipping + other charges
        public BigDecimal $total,
    ) {}
}
