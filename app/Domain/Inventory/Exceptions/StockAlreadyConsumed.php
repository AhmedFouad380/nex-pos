<?php

namespace App\Domain\Inventory\Exceptions;

use App\Domain\Catalog\Models\Variation;
use App\Support\DomainException;

/** A receipt can't be reversed once part of its stock was sold/issued — use a return document instead. */
final class StockAlreadyConsumed extends DomainException
{
    public static function for(Variation $variation): self
    {
        return new self(__('inventory.errors.already_consumed', ['item' => $variation->fullName()]));
    }
}
