<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Catalog\Models\Variation;
use App\Domain\Inventory\Data\StockIssue;
use App\Domain\Inventory\Enums\CostingMethod;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\StockAlreadyConsumed;
use App\Domain\Inventory\Models\StockLayer;
use App\Domain\Inventory\Models\StockLayerConsumption;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Settings\InventorySettings;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only place that changes stock. Every change is an append-only stock_movement;
 * stock_levels is a cache updated in the same transaction; stock_layers hold FIFO/LIFO cost.
 *
 * Quantities are always in the product's BASE unit, costs are per base unit.
 * A "source" is the document line that caused the movement (purchase item, sale item, adjustment item...).
 */
final class StockLedger
{
    public function __construct(private readonly InventorySettings $settings) {}

    /** Stock coming in: creates a movement, a cost layer and raises the level. */
    public function receive(
        Variation $variation,
        int $locationId,
        BigDecimal|string|int $qty,
        BigDecimal|string|int $unitCost,
        StockMovementType $type,
        Model $source,
        ?int $lotId = null,
        ?CarbonInterface $at = null,
        ?string $expDate = null,
    ): StockMovement {
        $qty = Decimal::of($qty);
        $unitCost = Decimal::of($unitCost);
        $this->assertTracked($variation);
        $this->assertPositive($qty);
        $at ??= now();

        return DB::transaction(function () use ($variation, $locationId, $qty, $unitCost, $type, $source, $lotId, $at, $expDate) {
            $level = $this->lockLevel($variation->id, $locationId);

            $movement = $this->movement($variation, $locationId, $qty, $unitCost, $type, $source, $lotId, $at);

            // If the item was oversold (negative on hand), this receipt first covers that shortfall,
            // so only the rest stays available in the layer — keeps SUM(layers) == on hand.
            $onHand = Decimal::of($level->qty_on_hand);
            $available = $onHand->isNegative() ? BigDecimal::max(BigDecimal::zero(), $qty->plus($onHand)) : $qty;

            StockLayer::query()->create([
                'location_id' => $locationId,
                'variation_id' => $variation->id,
                'lot_id' => $lotId,
                'source_type' => $source->getMorphClass(),
                'source_id' => $source->getKey(),
                'received_at' => $at,
                'exp_date' => $expDate,
                'original_qty' => Decimal::toDb($qty),
                'remaining_qty' => Decimal::toDb($available),
                'unit_cost' => Decimal::toDb($unitCost),
            ]);

            $this->applyToLevel($level, $qty, $unitCost);

            return $movement;
        });
    }

    /**
     * Stock going out: consumes cost layers (FIFO / LIFO / FEFO for lots) and lowers the level.
     * The returned unit cost is what the caller stores as the line's cost (COGS).
     */
    public function issue(
        Variation $variation,
        int $locationId,
        BigDecimal|string|int $qty,
        StockMovementType $type,
        Model $source,
        ?int $lotId = null,
        ?CarbonInterface $at = null,
        ?bool $allowNegative = null,
        ?Model $fromSource = null, // consume this line's layers first (e.g. return to supplier from its purchase)
    ): StockIssue {
        $qty = Decimal::of($qty);
        $this->assertTracked($variation);
        $this->assertPositive($qty);
        $at ??= now();
        $allowNegative ??= $this->settings->allow_overselling;

        return DB::transaction(function () use ($variation, $locationId, $qty, $type, $source, $lotId, $at, $allowNegative, $fromSource) {
            $level = $this->lockLevel($variation->id, $locationId);
            $method = CostingMethod::from($this->settings->costing_method);

            $remaining = $qty;
            $totalCost = BigDecimal::zero();

            foreach ($this->layersToConsume($variation, $locationId, $lotId, $method, $type, $fromSource) as $layer) {
                if (! $remaining->isPositive()) {
                    break;
                }
                $take = BigDecimal::min($remaining, Decimal::of($layer->remaining_qty));
                $layer->update(['remaining_qty' => Decimal::toDb(Decimal::of($layer->remaining_qty)->minus($take))]);

                $layerCost = $method === CostingMethod::Avg ? Decimal::of($level->avg_cost) : Decimal::of($layer->unit_cost);
                StockLayerConsumption::query()->create([
                    'stock_layer_id' => $layer->id,
                    'consumer_type' => $source->getMorphClass(),
                    'consumer_id' => $source->getKey(),
                    'qty' => Decimal::toDb($take),
                    'unit_cost' => Decimal::toDb($layerCost),
                ]);

                $totalCost = $totalCost->plus($take->multipliedBy($layerCost));
                $remaining = $remaining->minus($take);
            }

            if ($remaining->isPositive()) {
                if (! $allowNegative) {
                    throw InsufficientStock::for($variation, Decimal::toDb($qty), Decimal::toDb($qty->minus($remaining)));
                }
                // Overselling: the uncovered part has no layer; cost it at the best estimate we have.
                $estimate = $this->estimatedCost($variation, $level);
                $totalCost = $totalCost->plus($remaining->multipliedBy($estimate));
            }

            $unitCost = Decimal::divide($totalCost, $qty);
            $movement = $this->movement($variation, $locationId, $qty->negated(), $unitCost, $type, $source, $lotId, $at);
            $this->applyToLevel($level, $qty->negated(), $unitCost);

            return new StockIssue($movement, $qty, $unitCost, Decimal::of(Decimal::toDb($totalCost)));
        });
    }

    /**
     * Undo every live movement of a source (used when a document is voided or a line is edited):
     * issues give their quantity back to the layers they consumed; receipts remove their layer
     * (refused if part of it was already consumed).
     */
    public function reverse(Model $source, ?CarbonInterface $at = null): void
    {
        $at ??= now();

        DB::transaction(function () use ($source, $at) {
            $movements = StockMovement::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->whereNull('reverses_id')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('stock_movements as r')->whereColumn('r.reverses_id', 'stock_movements.id'))
                ->with('variation')
                ->get();

            foreach ($movements as $movement) {
                $level = $this->lockLevel($movement->variation_id, $movement->location_id);
                $qty = Decimal::of($movement->qty);

                // one line can move several items (combo components) — undo each movement on its own item only
                if ($qty->isNegative()) {
                    $this->restoreConsumptions($source, $movement->variation_id, $movement->location_id);
                } else {
                    $this->removeLayers($source, $movement->variation, $movement->location_id);
                }

                StockMovement::query()->create([
                    ...$movement->only(['location_id', 'variation_id', 'lot_id', 'unit_cost', 'type', 'source_type', 'source_id']),
                    'qty' => Decimal::toDb($qty->negated()),
                    'reverses_id' => $movement->id,
                    'moved_at' => $at,
                    'created_by' => auth()->id(),
                ]);

                $this->applyToLevel($level, $qty->negated(), Decimal::of($movement->unit_cost));
            }
        });
    }

    /** Current on-hand quantity (base unit). */
    public function onHand(int $variationId, int $locationId): BigDecimal
    {
        return Decimal::of(Decimal::toDb(StockLevel::query()
            ->where('variation_id', $variationId)->where('location_id', $locationId)
            ->value('qty_on_hand')));
    }

    // ---------------------------------------------------------------------------------------------

    private function assertTracked(Variation $variation): void
    {
        if (! $variation->product->tracksStock()) {
            throw new DomainException(__('inventory.errors.not_stock_tracked', ['item' => $variation->fullName()]));
        }
    }

    private function assertPositive(BigDecimal $qty): void
    {
        if (! $qty->isPositive()) {
            throw new DomainException(__('inventory.errors.non_positive_qty'));
        }
    }

    /** Lock (or create) the level row so concurrent movements on the same item serialize. */
    private function lockLevel(int $variationId, int $locationId): StockLevel
    {
        StockLevel::query()->firstOrCreate(['variation_id' => $variationId, 'location_id' => $locationId]);

        return StockLevel::query()
            ->where('variation_id', $variationId)->where('location_id', $locationId)
            ->lockForUpdate()->firstOrFail();
    }

    private function movement(Variation $variation, int $locationId, BigDecimal $qty, BigDecimal $unitCost, StockMovementType $type,
        Model $source, ?int $lotId, CarbonInterface $at): StockMovement
    {
        return StockMovement::query()->create([
            'location_id' => $locationId,
            'variation_id' => $variation->id,
            'lot_id' => $lotId,
            'qty' => Decimal::toDb($qty),
            'unit_cost' => Decimal::toDb($unitCost),
            'type' => $type,
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'moved_at' => $at,
            'created_by' => auth()->id(),
        ]);
    }

    /** Update quantity and moving-average cost (average only changes when stock comes in). */
    private function applyToLevel(StockLevel $level, BigDecimal $delta, BigDecimal $unitCost): void
    {
        $oldQty = Decimal::of($level->qty_on_hand);
        $newQty = $oldQty->plus($delta);
        $avg = Decimal::of($level->avg_cost);

        if ($delta->isPositive()) {
            $avg = $oldQty->isPositive()
                ? Decimal::divide($oldQty->multipliedBy($avg)->plus($delta->multipliedBy($unitCost)), $newQty)
                : $unitCost;
        } elseif ($delta->isNegative() && $newQty->isPositive()) {
            // value-based: what is left is worth (old value - value taken out). With the Avg method
            // the issue cost equals the average, so it stays unchanged; with FIFO it tracks the remaining layers.
            $avg = Decimal::divide($oldQty->multipliedBy($avg)->plus($delta->multipliedBy($unitCost)), $newQty);
        }

        $level->update([
            'qty_on_hand' => Decimal::toDb($newQty),
            'avg_cost' => Decimal::toDb($avg->isNegative() ? BigDecimal::zero() : $avg),
        ]);
    }

    /** @return iterable<StockLayer> */
    private function layersToConsume(Variation $variation, int $locationId, ?int $lotId, CostingMethod $method, StockMovementType $type, ?Model $fromSource = null): iterable
    {
        $query = StockLayer::query()
            ->where('variation_id', $variation->id)
            ->where('location_id', $locationId)
            ->where('remaining_qty', '>', 0)
            ->when($lotId, fn ($q) => $q->where('lot_id', $lotId))
            // prefer the layers of a given line first (a return to the supplier takes back that purchase's own stock),
            // then fall back to the normal order if part of it was already sold
            ->when($fromSource, fn ($q) => $q->orderByRaw('case when source_type = ? and source_id = ? then 0 else 1 end',
                [$fromSource->getMorphClass(), $fromSource->getKey()]))
            ->lockForUpdate();

        $product = $variation->product;
        if ($product->track_expiry) {
            if ($type === StockMovementType::Sale && $this->settings->stop_selling_days_before_expiry !== null) {
                $cutoff = now()->addDays($this->settings->stop_selling_days_before_expiry)->toDateString();
                $query->where(fn ($q) => $q->whereNull('exp_date')->orWhere('exp_date', '>', $cutoff));
            }
            // FEFO: nearest expiry first, undated last
            $query->orderByRaw('exp_date is null')->orderBy('exp_date');
        }

        $direction = $method === CostingMethod::Lifo ? 'desc' : 'asc';

        return $query->orderBy('received_at', $direction)->orderBy('id', $direction)->get();
    }

    private function estimatedCost(Variation $variation, StockLevel $level): BigDecimal
    {
        $avg = Decimal::of($level->avg_cost);
        if ($avg->isPositive()) {
            return $avg;
        }
        $last = StockLayer::query()->where('variation_id', $variation->id)->latest('received_at')->latest('id')->value('unit_cost');

        return $last !== null ? Decimal::of($last) : Decimal::of($variation->purchase_price);
    }

    private function restoreConsumptions(Model $source, int $variationId, int $locationId): void
    {
        $consumptions = StockLayerConsumption::query()
            ->where('consumer_type', $source->getMorphClass())
            ->where('consumer_id', $source->getKey())
            ->whereHas('stockLayer', fn ($q) => $q->where('variation_id', $variationId)->where('location_id', $locationId))
            ->lockForUpdate()
            ->get();

        foreach ($consumptions as $consumption) {
            $layer = StockLayer::query()->whereKey($consumption->stock_layer_id)->lockForUpdate()->firstOrFail();
            $layer->update(['remaining_qty' => Decimal::toDb(Decimal::of($layer->remaining_qty)->plus(Decimal::of($consumption->qty)))]);
            $consumption->delete();
        }
    }

    private function removeLayers(Model $source, Variation $variation, int $locationId): void
    {
        $layers = StockLayer::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('variation_id', $variation->id)
            ->where('location_id', $locationId)
            ->lockForUpdate()
            ->get();

        foreach ($layers as $layer) {
            if (! Decimal::of($layer->remaining_qty)->isEqualTo(Decimal::of($layer->original_qty))) {
                throw StockAlreadyConsumed::for($variation);
            }
            $layer->update(['remaining_qty' => '0']);
        }
    }
}
