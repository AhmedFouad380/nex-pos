<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\StockLedger;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Puts the stock into the destination at exactly the cost it left the source with. */
final class ReceiveStockTransfer
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== StockTransferStatus::InTransit) {
            throw new DomainException(__('inventory.errors.invalid_status', ['status' => $transfer->status->getLabel()]));
        }

        return DB::transaction(function () use ($transfer) {
            foreach ($transfer->items()->with(['variation.product', 'lot'])->get() as $item) {
                $outCost = StockMovement::query()
                    ->where('source_type', $item->getMorphClass())->where('source_id', $item->id)
                    ->where('type', StockMovementType::TransferOut)->whereNull('reverses_id')
                    ->value('unit_cost'); // per base unit, unrounded by unit conversion

                $this->ledger->receive($item->variation, $transfer->to_location_id, $item->base_qty, $outCost,
                    StockMovementType::TransferIn, $item, $item->lot_id, expDate: $item->lot?->exp_date?->toDateString());
                $item->update(['qty_received' => $item->qty_sent]);
            }

            $transfer->update([
                'status' => StockTransferStatus::Received,
                'received_at' => now(),
                'received_by' => auth()->id(),
            ]);

            return $transfer;
        });
    }
}
