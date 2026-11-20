<?php

namespace App\Domain\Sales\Data;

use App\Domain\Shared\Enums\DiscountType;

/** A cart line. Price is per chosen unit "as shown" (tax-inclusive for inclusive products). */
final readonly class SaleLineData
{
    public function __construct(
        public int $variationId,
        public string $qty,
        public ?int $unitId = null,          // null = the product's base unit
        public ?string $price = null,        // null = catalog / price-group price
        public ?DiscountType $discountType = null,
        public string $discountValue = '0',  // fixed discount is per unit, in shown terms
        public ?string $note = null,
        public ?int $serviceStaffId = null,
        public ?int $lotId = null,
    ) {}

    public static function fromArray(array $line): self
    {
        return new self(
            variationId: (int) $line['variation_id'],
            qty: (string) $line['qty'],
            unitId: filled($line['unit_id'] ?? null) ? (int) $line['unit_id'] : null,
            price: filled($line['price'] ?? null) ? (string) $line['price'] : null,
            discountType: DiscountType::coerce($line['discount_type'] ?? null),
            discountValue: (string) ($line['discount_value'] ?? 0),
            note: $line['note'] ?? null,
            serviceStaffId: filled($line['service_staff_id'] ?? null) ? (int) $line['service_staff_id'] : null,
            lotId: filled($line['lot_id'] ?? null) ? (int) $line['lot_id'] : null,
        );
    }
}
