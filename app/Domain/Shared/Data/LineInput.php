<?php

namespace App\Domain\Shared\Data;

use App\Domain\Shared\Enums\DiscountType;

/** One document line before calculation. Price is per entered unit, before line discount, excluding tax. */
final readonly class LineInput
{
    public function __construct(
        public string $qty,
        public string $price,
        public ?DiscountType $discountType = null,
        public string $discountValue = '0',
        public string $taxRate = '0', // percent
    ) {}
}
