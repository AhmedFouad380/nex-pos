<?php

namespace App\Domain\Inventory\Data;

use App\Domain\Inventory\Models\StockMovement;
use Brick\Math\BigDecimal;

/** Result of StockLedger::issue(): the cost of what left the shelf. */
final readonly class StockIssue
{
    public function __construct(
        public StockMovement $movement,
        public BigDecimal $qty,
        public BigDecimal $unitCost,
        public BigDecimal $totalCost,
    ) {}
}
