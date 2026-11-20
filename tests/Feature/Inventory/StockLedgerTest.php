<?php

use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\StockMovementType as T;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\StockAlreadyConsumed;
use App\Domain\Inventory\Models\Lot;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentItem;
use App\Domain\Inventory\Models\StockLayer;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\StockLedger;
use App\Settings\InventorySettings;
use App\Support\DomainException;

beforeEach(function () {
    $this->skipLedgerCheck = true; // exercises the stock engine directly, without documents that post
    seedBaseData();
    $this->location = Location::query()->firstOrFail()->id;
    $this->variation = makeVariation();
    $this->ledger = fn () => app(StockLedger::class);
    // any persisted model works as a "source" line; adjustment items are the simplest
    $this->source = fn () => StockAdjustmentItem::query()->forceCreate([
        'stock_adjustment_id' => StockAdjustment::query()->forceCreate([
            'number' => uniqid(), 'location_id' => $this->location, 'adjusted_at' => now(), 'type' => 'increase',
        ])->id,
        'variation_id' => $this->variation->id, 'unit_id' => $this->variation->product->unit_id, 'qty' => 0, 'base_qty' => 0,
    ]);
});

function setCosting(string $method, bool $oversell = false): void
{
    $s = app(InventorySettings::class);
    $s->costing_method = $method;
    $s->allow_overselling = $oversell;
    $s->save();
}

it('receives stock into a layer and the level', function () {
    ($this->ledger)()->receive($this->variation, $this->location, '10', '5', T::Purchase, ($this->source)());

    expect((string) ($this->ledger)()->onHand($this->variation->id, $this->location))->toBe('10.0000')
        ->and(StockLayer::query()->sole()->remaining_qty)->toBe('10.0000');
    assertStockConsistent();
});

it('costs issues FIFO across layers', function () {
    setCosting('fifo');
    ($this->ledger)()->receive($this->variation, $this->location, 10, 5, T::Purchase, ($this->source)(), at: now()->subDays(2));
    ($this->ledger)()->receive($this->variation, $this->location, 10, 7, T::Purchase, ($this->source)(), at: now()->subDay());

    $issue = ($this->ledger)()->issue($this->variation, $this->location, 15, T::Sale, ($this->source)());

    expect((string) $issue->totalCost)->toBe('85.0000')          // 10×5 + 5×7
        ->and((string) $issue->unitCost)->toBe('5.6667')
        ->and((string) ($this->ledger)()->onHand($this->variation->id, $this->location))->toBe('5.0000');
    assertStockConsistent();
});

it('costs issues LIFO across layers', function () {
    setCosting('lifo');
    ($this->ledger)()->receive($this->variation, $this->location, 10, 5, T::Purchase, ($this->source)(), at: now()->subDays(2));
    ($this->ledger)()->receive($this->variation, $this->location, 10, 7, T::Purchase, ($this->source)(), at: now()->subDay());

    expect((string) ($this->ledger)()->issue($this->variation, $this->location, 15, T::Sale, ($this->source)())->totalCost)
        ->toBe('95.0000'); // 10×7 + 5×5
    assertStockConsistent();
});

it('costs issues at the moving average', function () {
    setCosting('avg');
    ($this->ledger)()->receive($this->variation, $this->location, 10, 5, T::Purchase, ($this->source)());
    ($this->ledger)()->receive($this->variation, $this->location, 10, 7, T::Purchase, ($this->source)());

    expect((string) ($this->ledger)()->issue($this->variation, $this->location, 15, T::Sale, ($this->source)())->totalCost)
        ->toBe('90.0000'); // 15 × 6
    assertStockConsistent();
});

it('refuses to go negative and rolls everything back', function () {
    setCosting('fifo', oversell: false);
    ($this->ledger)()->receive($this->variation, $this->location, 3, 5, T::Purchase, ($this->source)());

    expect(fn () => ($this->ledger)()->issue($this->variation, $this->location, 5, T::Sale, ($this->source)()))
        ->toThrow(InsufficientStock::class);

    expect((string) ($this->ledger)()->onHand($this->variation->id, $this->location))->toBe('3.0000')
        ->and(StockMovement::query()->count())->toBe(1)
        ->and(StockLayer::query()->sole()->remaining_qty)->toBe('3.0000');
});

it('allows overselling when enabled and the next receipt covers the shortfall', function () {
    setCosting('fifo', oversell: true);
    ($this->ledger)()->receive($this->variation, $this->location, 2, 5, T::Purchase, ($this->source)());

    $issue = ($this->ledger)()->issue($this->variation, $this->location, 5, T::Sale, ($this->source)());
    expect((string) $issue->totalCost)->toBe('25.0000') // 2 from the layer + 3 estimated at avg cost 5
        ->and((string) ($this->ledger)()->onHand($this->variation->id, $this->location))->toBe('-3.0000');

    ($this->ledger)()->receive($this->variation, $this->location, 10, 6, T::Purchase, ($this->source)());

    expect((string) ($this->ledger)()->onHand($this->variation->id, $this->location))->toBe('7.0000')
        ->and(StockLayer::query()->sum('remaining_qty'))->toEqual(7);
    assertStockConsistent();
});

it('reverses an issue back into the layers it consumed', function () {
    setCosting('fifo');
    ($this->ledger)()->receive($this->variation, $this->location, 10, 5, T::Purchase, ($this->source)());
    $sale = ($this->source)();
    ($this->ledger)()->issue($this->variation, $this->location, 4, T::Sale, $sale);

    ($this->ledger)()->reverse($sale);
    ($this->ledger)()->reverse($sale); // idempotent: nothing left to reverse

    expect((string) ($this->ledger)()->onHand($this->variation->id, $this->location))->toBe('10.0000')
        ->and(StockLayer::query()->sole()->remaining_qty)->toBe('10.0000')
        ->and(StockMovement::query()->whereNotNull('reverses_id')->count())->toBe(1);
    assertStockConsistent();
});

it('refuses to reverse a receipt whose stock was already sold', function () {
    $purchase = ($this->source)();
    ($this->ledger)()->receive($this->variation, $this->location, 10, 5, T::Purchase, $purchase);
    ($this->ledger)()->issue($this->variation, $this->location, 1, T::Sale, ($this->source)());

    expect(fn () => ($this->ledger)()->reverse($purchase))->toThrow(StockAlreadyConsumed::class);
    assertStockConsistent();
});

it('reverses an untouched receipt completely', function () {
    $purchase = ($this->source)();
    ($this->ledger)()->receive($this->variation, $this->location, 10, 5, T::Purchase, $purchase);

    ($this->ledger)()->reverse($purchase);

    expect((string) ($this->ledger)()->onHand($this->variation->id, $this->location))->toBe('0.0000');
    assertStockConsistent();
});

it('sells the nearest expiry first and skips lots too close to expiring', function () {
    setCosting('fifo');
    $variation = makeVariation(['track_expiry' => true, 'track_lots' => true]);
    $later = Lot::query()->create(['variation_id' => $variation->id, 'number' => 'L-LATE', 'exp_date' => now()->addMonths(6)]);
    $soon = Lot::query()->create(['variation_id' => $variation->id, 'number' => 'L-SOON', 'exp_date' => now()->addDays(20)]);
    ($this->ledger)()->receive($variation, $this->location, 5, 10, T::Purchase, ($this->source)(), $later->id, now()->subDays(5), $later->exp_date->toDateString());
    ($this->ledger)()->receive($variation, $this->location, 5, 8, T::Purchase, ($this->source)(), $soon->id, now(), $soon->exp_date->toDateString());

    // FEFO: the lot expiring soon goes first even though it arrived later
    expect((string) ($this->ledger)()->issue($variation, $this->location, 2, T::Adjustment, ($this->source)())->unitCost)->toBe('8.0000');

    $s = app(InventorySettings::class);
    $s->stop_selling_days_before_expiry = 30;
    $s->save();

    // sales must skip the lot expiring within 30 days
    expect((string) ($this->ledger)()->issue($variation, $this->location, 2, T::Sale, ($this->source)())->unitCost)->toBe('10.0000');
    assertStockConsistent();
});

it('does not track stock for services', function () {
    $service = makeVariation(['type' => ProductType::Service, 'track_stock' => false]);

    expect(fn () => ($this->ledger)()->receive($service, $this->location, 1, 1, T::Purchase, ($this->source)()))
        ->toThrow(DomainException::class);
});
