<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\UnitConverter;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Inventory\Data\StockLineData;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use App\Support\DomainException;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/** Creates a draft transfer between two locations; nothing moves until it is sent. */
final class CreateStockTransfer
{
    public function __construct(
        private readonly UnitConverter $units,
        private readonly DocumentNumberGenerator $numbers,
        private readonly SendStockTransfer $send,
    ) {}

    /** @param list<StockLineData> $lines */
    public function handle(int $fromLocationId, int $toLocationId, array $lines, ?CarbonInterface $date = null,
        ?string $note = null, string|int|null $shippingCost = null, bool $sendNow = false): StockTransfer
    {
        if ($fromLocationId === $toLocationId) {
            throw new DomainException(__('inventory.errors.same_location'));
        }
        if ($lines === []) {
            throw new DomainException(__('inventory.errors.no_items'));
        }

        return DB::transaction(function () use ($fromLocationId, $toLocationId, $lines, $date, $note, $shippingCost, $sendNow) {
            $transfer = StockTransfer::query()->create([
                'number' => $this->numbers->next(DocumentType::StockTransfer, $fromLocationId),
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'status' => StockTransferStatus::Draft,
                'transfer_date' => $date ?? now(),
                'shipping_cost' => Decimal::toDb($shippingCost),
                'note' => $note,
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $line) {
                $variation = Variation::query()->with('product')->findOrFail($line->variationId);
                $multiplier = $this->units->multiplier($variation->product, Unit::query()->findOrFail($line->unitId));
                $qty = Decimal::of($line->qty);
                if (! $qty->isPositive()) {
                    throw new DomainException(__('inventory.errors.non_positive_qty'));
                }
                $transfer->items()->create([
                    'variation_id' => $variation->id,
                    'unit_id' => $line->unitId,
                    'unit_multiplier' => Decimal::toDb($multiplier),
                    'qty_sent' => Decimal::toDb($qty),
                    'base_qty' => Decimal::toDb($qty->multipliedBy($multiplier)),
                    'lot_id' => $line->lotId,
                ]);
            }

            return $sendNow ? $this->send->handle($transfer) : $transfer;
        });
    }
}
