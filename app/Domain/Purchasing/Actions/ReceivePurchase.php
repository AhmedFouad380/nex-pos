<?php

namespace App\Domain\Purchasing\Actions;

use App\Domain\Finance\Services\Posting;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Pending → received: puts every stock-tracked line into the location at its landed cost. */
final class ReceivePurchase
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(Purchase $purchase): Purchase
    {
        if ($purchase->status === PurchaseStatus::Received) {
            throw new DomainException(__('inventory.errors.invalid_status', ['status' => $purchase->status->getLabel()]));
        }
        if ($purchase->voided_at !== null) {
            throw new DomainException(__('inventory.errors.already_voided'));
        }

        return DB::transaction(function () use ($purchase) {
            foreach ($purchase->items()->with(['variation.product', 'lot'])->get() as $item) {
                if (! $item->variation->product->tracksStock()) {
                    continue;
                }
                $this->ledger->receive($item->variation, $purchase->location_id, $item->base_qty, $item->landed_unit_cost,
                    StockMovementType::Purchase, $item, $item->lot_id, $purchase->purchase_date, $item->exp_date?->toDateString());
            }
            $purchase->update(['status' => PurchaseStatus::Received]);

            app(Posting::class)->sync($purchase);

            return $purchase;
        });
    }
}
