<?php

namespace App\Domain\Purchasing\Data;

use App\Domain\Purchasing\Enums\CostDistribution;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Shared\Enums\DiscountType;
use Carbon\CarbonInterface;

final readonly class PurchaseData
{
    /**
     * @param  list<PurchaseLineData>  $lines
     * @param  list<array{name: string, amount: string|float|int, distribute_by?: CostDistribution|string}>  $costs  landed costs
     */
    public function __construct(
        public int $supplierId,
        public int $locationId,
        public array $lines,
        public PurchaseStatus $status = PurchaseStatus::Received,
        public ?CarbonInterface $date = null,
        public ?string $dueDate = null,
        public ?string $supplierRef = null,
        public ?int $currencyId = null,
        public string $exchangeRate = '1',
        public ?DiscountType $discountType = null,
        public string $discountValue = '0',
        public ?int $taxRateId = null,
        public string $shippingCharges = '0',
        public ?string $shippingDetails = null,
        public array $costs = [],
        public ?string $note = null,
        public ?int $purchaseOrderId = null,
    ) {}
}
