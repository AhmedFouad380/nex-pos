<?php

namespace App\Domain\Purchasing\Data;

use App\Domain\Shared\Enums\DiscountType;

/** A purchase / purchase order line as entered: cost per chosen unit, in the document currency, before discount & tax. */
final readonly class PurchaseLineData
{
    public function __construct(
        public int $variationId,
        public int $unitId,
        public string $qty,
        public string $unitCost,
        public ?DiscountType $discountType = null,
        public string $discountValue = '0',
        public ?int $taxRateId = null,
        public ?string $lotNumber = null,
        public ?string $mfgDate = null,
        public ?string $expDate = null,
        public ?string $newSellPrice = null,
        public ?int $purchaseOrderItemId = null,
    ) {}

    public static function fromArray(array $line): self
    {
        $discountType = $line['discount_type'] ?? null;

        return new self(
            variationId: (int) $line['variation_id'],
            unitId: (int) $line['unit_id'],
            qty: (string) $line['qty'],
            unitCost: (string) ($line['unit_cost'] ?? 0),
            discountType: $discountType instanceof DiscountType ? $discountType : DiscountType::tryFrom((string) $discountType),
            discountValue: (string) ($line['discount_value'] ?? 0),
            taxRateId: filled($line['tax_rate_id'] ?? null) ? (int) $line['tax_rate_id'] : null,
            lotNumber: ($line['lot_number'] ?? null) ?: null,
            mfgDate: ($line['mfg_date'] ?? null) ?: null,
            expDate: ($line['exp_date'] ?? null) ?: null,
            newSellPrice: filled($line['new_sell_price'] ?? null) ? (string) $line['new_sell_price'] : null,
            purchaseOrderItemId: filled($line['purchase_order_item_id'] ?? null) ? (int) $line['purchase_order_item_id'] : null,
        );
    }
}
