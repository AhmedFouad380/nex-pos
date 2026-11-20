<?php

namespace App\Domain\Sales\Data;

use App\Domain\Sales\Enums\SaleChannel;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Shared\Enums\DiscountType;
use Carbon\CarbonInterface;

final readonly class SaleData
{
    /**
     * @param  list<SaleLineData>  $lines
     * @param  list<array{payment_method_id: int, amount: string|int|float, reference?: string|null}>  $tenders  money handed over (cash may exceed the total → change)
     */
    public function __construct(
        public int $locationId,
        public array $lines,
        public SaleStatus $status = SaleStatus::Final,
        public ?int $customerId = null,     // null = walk-in customer
        public SaleChannel $channel = SaleChannel::Pos,
        public ?int $cashSessionId = null,
        public ?int $priceGroupId = null,
        public ?DiscountType $discountType = null,
        public string $discountValue = '0',
        public ?int $taxRateId = null,
        public string $shippingCharges = '0',
        public string $packingCharge = '0',
        public array $tenders = [],
        public ?CarbonInterface $date = null,
        public ?string $note = null,
        public ?string $staffNote = null,
        public ?int $serviceTypeId = null,
        public ?int $tableId = null,
        public ?int $waiterId = null,
        public ?int $commissionAgentId = null,
        public ?string $validUntil = null,
        public ?string $shippingAddress = null,
    ) {}
}
