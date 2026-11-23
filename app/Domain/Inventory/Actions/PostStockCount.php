<?php

namespace App\Domain\Inventory\Actions;

use App\Domain\Inventory\Data\StockLineData;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountItem;
use App\Domain\Inventory\Services\StockLedger;
use App\Support\Decimal;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Posts a stock count: the counted quantity becomes the branch's stock. Each counted line is compared with the stock
 * on hand at this moment (sales made during the count are already in it); shortages go out in one decrease adjustment,
 * surpluses come in at the line's cost in one increase adjustment, and both post their accounting entries.
 * Lines left uncounted are not touched — or count as zero when asked.
 */
final class PostStockCount
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly CreateStockAdjustment $adjustments,
    ) {}

    public function handle(StockCount $count, bool $uncountedAsZero = false): StockCount
    {
        return DB::transaction(function () use ($count, $uncountedAsZero) {
            $count = StockCount::query()->lockForUpdate()->findOrFail($count->id);
            if ($count->status !== StockCountStatus::Counting) {
                throw new DomainException(__('inventory.errors.invalid_status', ['status' => $count->status->getLabel()]));
            }
            $items = $count->items()->with('variation.product')->get();
            if (! $uncountedAsZero && $items->whereNotNull('counted_qty')->isEmpty()) {
                throw new DomainException(__('inventory.errors.count_nothing'));
            }

            $shortages = [];
            $surpluses = [];
            foreach ($items as $item) {
                /** @var StockCountItem $item */
                if ($item->counted_qty === null && ! $uncountedAsZero) {
                    continue;
                }
                $counted = Decimal::of($item->counted_qty ?? 0);
                $onHand = $this->ledger->onHand($item->variation_id, $count->location_id);
                $difference = $counted->minus($onHand);
                $item->update(['counted_qty' => Decimal::toDb($counted), 'expected_qty' => Decimal::toDb($onHand), 'difference' => Decimal::toDb($difference)]);
                if ($difference->isZero()) {
                    continue;
                }
                $line = new StockLineData($item->variation_id, $item->variation->product->unit_id, Decimal::toDb($difference->abs()),
                    $difference->isPositive() ? Decimal::toDb($item->unit_cost) : null);
                $difference->isPositive() ? $surpluses[] = $line : $shortages[] = $line;
            }

            $reason = __('Stock count :number', ['number' => $count->number]);
            $count->update([
                'status' => StockCountStatus::Posted,
                'posted_at' => now(),
                'stock_adjustment_id' => $shortages ? $this->adjustments->handle($count->location_id, StockAdjustmentType::Decrease, $shortages, reason: $reason)->id : null,
                'surplus_adjustment_id' => $surpluses ? $this->adjustments->handle($count->location_id, StockAdjustmentType::Increase, $surpluses, reason: $reason)->id : null,
            ]);

            return $count->refresh();
        });
    }
}
