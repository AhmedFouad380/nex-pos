<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Finance\Services\Posting;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Services\StockLedger;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Cancels an adjustment by reversing its movements (refused if received stock was already sold). */
final class VoidStockAdjustment
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(StockAdjustment $adjustment, string $reason): StockAdjustment
    {
        if ($adjustment->voided_at !== null) {
            throw new DomainException(__('inventory.errors.already_voided'));
        }

        return DB::transaction(function () use ($adjustment, $reason) {
            foreach ($adjustment->items as $item) {
                $this->ledger->reverse($item);
            }
            $adjustment->update(['voided_at' => now(), 'void_reason' => $reason]);

            app(Posting::class)->sync($adjustment);

            return $adjustment;
        });
    }
}
