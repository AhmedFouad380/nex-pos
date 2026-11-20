<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Catalog\Models\Variation;
use App\Support\DomainException;

final class InsufficientStock extends DomainException
{
    public static function for(Variation $variation, string $requested, string $available): self
    {
        return new self(__('inventory.errors.insufficient_stock', [
            'item' => $variation->fullName(),
            'requested' => rtrim(rtrim($requested, '0'), '.'),
            'available' => rtrim(rtrim($available, '0'), '.'),
        ]));
    }
}
