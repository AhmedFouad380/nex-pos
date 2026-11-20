<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\UnitConverter;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Services\DocumentNumberGenerator;
use App\Domain\Inventory\Data\StockLineData;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Models\Lot;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Shared\Enums\DocumentType;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Opening stock, increases (found stock) and decreases (damage, expiry, loss).
 * Incoming lines create cost layers at the entered cost; outgoing lines are costed by the ledger.
 */
final class CreateStockAdjustment
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly UnitConverter $units,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /** @param list<StockLineData> $lines */
    public function handle(
        int $locationId,
        StockAdjustmentType $type,
        array $lines,
        ?CarbonInterface $date = null,
        ?string $reason = null,
        ?string $note = null,
        string|int|null $recoveredAmount = null,
    ): StockAdjustment {
        if ($lines === []) {
            throw new DomainException(__('inventory.errors.no_items'));
        }
        $date ??= now();
        $incoming = in_array($type, [StockAdjustmentType::Opening, StockAdjustmentType::Increase], true);

        return DB::transaction(function () use ($locationId, $type, $lines, $date, $reason, $note, $recoveredAmount, $incoming) {
            $adjustment = StockAdjustment::query()->create([
                'number' => $this->numbers->next(DocumentType::StockAdjustment, $locationId),
                'location_id' => $locationId,
                'adjusted_at' => $date,
                'type' => $type,
                'reason' => $reason,
                'note' => $note,
                'recovered_amount' => Decimal::toDb($recoveredAmount),
                'created_by' => auth()->id(),
            ]);

            $total = BigDecimal::zero();

            foreach ($lines as $line) {
                $variation = Variation::query()->with('product')->findOrFail($line->variationId);
                $multiplier = $this->units->multiplier($variation->product, Unit::query()->findOrFail($line->unitId));
                $qty = Decimal::of($line->qty);
                if (! $qty->isPositive()) {
                    throw new DomainException(__('inventory.errors.non_positive_qty'));
                }
                $baseQty = $qty->multipliedBy($multiplier);
                $lotId = $this->resolveLot($variation, $line);

                $item = $adjustment->items()->create([
                    'variation_id' => $variation->id,
                    'unit_id' => $line->unitId,
                    'unit_multiplier' => Decimal::toDb($multiplier),
                    'qty' => Decimal::toDb($incoming ? $qty : $qty->negated()),
                    'base_qty' => Decimal::toDb($incoming ? $baseQty : $baseQty->negated()),
                    'lot_id' => $lotId,
                    'lot_number' => $line->lotNumber,
                    'exp_date' => $line->expDate,
                ]);

                if ($incoming) {
                    // entered cost is per chosen unit; layers are per base unit
                    $unitCost = Decimal::of($line->unitCost ?? Decimal::of($variation->purchase_price)->multipliedBy($multiplier));
                    $baseCost = Decimal::divide($unitCost, $multiplier);
                    $this->ledger->receive($variation, $locationId, $baseQty, $baseCost,
                        $type === StockAdjustmentType::Opening ? StockMovementType::Opening : StockMovementType::Adjustment,
                        $item, $lotId, $date, $line->expDate);
                    $lineTotal = $qty->multipliedBy($unitCost);
                } else {
                    $issue = $this->ledger->issue($variation, $locationId, $baseQty, StockMovementType::Adjustment, $item, $lotId, $date, allowNegative: false);
                    $unitCost = $issue->unitCost->multipliedBy($multiplier);
                    $lineTotal = $issue->totalCost;
                }

                $item->update(['unit_cost' => Decimal::toDb($unitCost), 'line_total' => Decimal::toDb($lineTotal)]);
                $total = $total->plus($lineTotal);
            }

            $adjustment->update(['total_cost' => Decimal::toDb($total)]);

            app(Posting::class)->sync($adjustment);

            return $adjustment->refresh();
        });
    }

    private function resolveLot(Variation $variation, StockLineData $line): ?int
    {
        if ($line->lotId) {
            return $line->lotId;
        }
        if (! $line->lotNumber) {
            return null;
        }

        return Lot::query()->firstOrCreate(
            ['variation_id' => $variation->id, 'number' => $line->lotNumber],
            ['exp_date' => $line->expDate],
        )->id;
    }
}
