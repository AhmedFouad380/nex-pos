<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\StockLedger;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Takes the stock out of the source location (status → in transit). */
final class SendStockTransfer
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(StockTransfer $transfer): StockTransfer
    {
        if ($transfer->status !== StockTransferStatus::Draft) {
            throw new DomainException(__('inventory.errors.invalid_status', ['status' => $transfer->status->getLabel()]));
        }

        return DB::transaction(function () use ($transfer) {
            $total = BigDecimal::zero();

            foreach ($transfer->items()->with('variation.product')->get() as $item) {
                $issue = $this->ledger->issue($item->variation, $transfer->from_location_id, $item->base_qty,
                    StockMovementType::TransferOut, $item, $item->lot_id, allowNegative: false);
                $item->update(['unit_cost' => Decimal::toDb($issue->unitCost->multipliedBy(Decimal::of($item->unit_multiplier)))]);
                $total = $total->plus($issue->totalCost);
            }

            $transfer->update([
                'status' => StockTransferStatus::InTransit,
                'sent_at' => now(),
                'total_cost' => Decimal::toDb($total),
            ]);

            return $transfer;
        });
    }
}
