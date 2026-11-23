<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\StockCount;
use App\Support\DomainException;

/** Drops a count that was not posted: nothing happened to the stock, the counted figures stay for reference. */
final class CancelStockCount
{
    public function handle(StockCount $count): StockCount
    {
        if ($count->status !== StockCountStatus::Counting) {
            throw new DomainException(__('inventory.errors.invalid_status', ['status' => $count->status->getLabel()]));
        }
        $count->update(['status' => StockCountStatus::Cancelled]);

        return $count;
    }
}
