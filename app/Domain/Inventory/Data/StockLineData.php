<?php

namespace App\Domain\Inventory\Data;

/** One line of an adjustment / transfer as entered by the user (qty in the chosen unit, always positive). */
final readonly class StockLineData
{
    public function __construct(
        public int $variationId,
        public int $unitId,
        public string $qty,
        public ?string $unitCost = null,   // per chosen unit; only for incoming lines (opening/increase)
        public ?int $lotId = null,
        public ?string $lotNumber = null,  // creates the lot if it doesn't exist yet
        public ?string $expDate = null,
    ) {}

    public static function fromArray(array $line): self
    {
        return new self(
            variationId: (int) $line['variation_id'],
            unitId: (int) $line['unit_id'],
            qty: (string) $line['qty'],
            unitCost: isset($line['unit_cost']) && $line['unit_cost'] !== '' ? (string) $line['unit_cost'] : null,
            lotId: isset($line['lot_id']) ? (int) $line['lot_id'] : null,
            lotNumber: $line['lot_number'] ?? null ?: null,
            expDate: $line['exp_date'] ?? null ?: null,
        );
    }
}
