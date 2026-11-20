<?php

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Unit;
use App\Support\DomainException;

final class InvalidUnit extends DomainException
{
    public static function for(Product $product, Unit $unit): self
    {
        return new self(__('inventory.errors.invalid_unit', ['unit' => $unit->name, 'item' => $product->name]));
    }
}
