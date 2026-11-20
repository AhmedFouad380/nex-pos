<?php

namespace App\Domain\Finance\Data;

use App\Domain\Finance\Enums\PaymentDirection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

final readonly class PaymentData
{
    /**
     * @param  list<array{0: Model, 1: string}>  $allocations  [document, amount] pairs
     * @param  bool  $autoAllocate  spread the unallocated rest over the contact's oldest open documents
     */
    public function __construct(
        public PaymentDirection $direction,
        public int $paymentMethodId,
        public string $amount,
        public ?int $contactId = null,
        public ?int $locationId = null,
        public ?CarbonInterface $paidAt = null,
        public array $allocations = [],
        public bool $autoAllocate = false,
        public ?int $accountId = null,
        public ?int $counterAccountId = null,
        public ?int $cashSessionId = null,
        public ?string $reference = null,
        public ?string $chequeNumber = null,
        public ?string $chequeDate = null,
        public ?string $bankName = null,
        public ?string $note = null,
    ) {}
}
