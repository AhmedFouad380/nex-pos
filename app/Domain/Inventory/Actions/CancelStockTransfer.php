<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\StockLedger;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Draft → cancelled; in transit → stock goes back to the source. Received transfers can't be cancelled. */
final class CancelStockTransfer
{
    public function __construct(private readonly StockLedger $ledger) {}

    public function handle(StockTransfer $transfer): StockTransfer
    {
        if (! in_array($transfer->status, [StockTransferStatus::Draft, StockTransferStatus::InTransit], true)) {
            throw new DomainException(__('inventory.errors.invalid_status', ['status' => $transfer->status->getLabel()]));
        }

        return DB::transaction(function () use ($transfer) {
            if ($transfer->status === StockTransferStatus::InTransit) {
                foreach ($transfer->items as $item) {
                    $this->ledger->reverse($item);
                }
            }
            $transfer->update(['status' => StockTransferStatus::Cancelled]);

            return $transfer;
        });
    }
}
