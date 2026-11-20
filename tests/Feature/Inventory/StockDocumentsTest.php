<?php

use App\Domain\Catalog\Models\Unit;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Actions\CancelStockTransfer;
use App\Domain\Inventory\Actions\CreateStockAdjustment;
use App\Domain\Inventory\Actions\CreateStockTransfer;
use App\Domain\Inventory\Actions\ReceiveStockTransfer;
use App\Domain\Inventory\Actions\VoidStockAdjustment;
use App\Domain\Inventory\Data\StockLineData;
use App\Domain\Inventory\Enums\StockAdjustmentType as A;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Exceptions\InsufficientStock;
use App\Domain\Inventory\Exceptions\StockAlreadyConsumed;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Services\StockLedger;
use App\Settings\InventorySettings;
use App\Support\DomainException;
use Illuminate\Support\Facades\Artisan;

beforeEach(function () {
    seedBaseData();
    $this->main = Location::query()->firstOrFail();
    $this->branch = Location::query()->create(['code' => 'B2', 'name' => 'فرع 2']);
    $this->piece = Unit::query()->where('name', 'قطعة')->firstOrFail();
    $this->carton = Unit::query()->where('name', 'كرتونة')->firstOrFail(); // 12 pieces
    $this->variation = makeVariation(['unit_id' => $this->piece->id], ['purchase_price' => 9]);
    $this->onHand = fn (Location $l) => (string) app(StockLedger::class)->onHand($this->variation->id, $l->id);
});

it('records opening stock entered in cartons as pieces at the per-piece cost', function () {
    $adj = app(CreateStockAdjustment::class)->handle($this->main->id, A::Opening, [
        new StockLineData($this->variation->id, $this->carton->id, '2', unitCost: '120'),
    ]);

    expect($adj->number)->toBe('ADJ-00001')
        ->and((string) $adj->total_cost)->toBe('240.0000')
        ->and(($this->onHand)($this->main))->toBe('24.0000')
        ->and(StockLevel::query()->sole()->avg_cost)->toBe('10.0000');
    assertStockConsistent();
});

it('defaults the cost of incoming lines to the purchase price', function () {
    app(CreateStockAdjustment::class)->handle($this->main->id, A::Increase, [
        new StockLineData($this->variation->id, $this->piece->id, '5'),
    ]);

    expect(StockLevel::query()->sole()->avg_cost)->toBe('9.0000');
});

it('costs a damage adjustment from the layers and voids it back', function () {
    app(CreateStockAdjustment::class)->handle($this->main->id, A::Opening, [new StockLineData($this->variation->id, $this->piece->id, '10', unitCost: '4')]);
    $damage = app(CreateStockAdjustment::class)->handle($this->main->id, A::Damage, [new StockLineData($this->variation->id, $this->piece->id, '3')], reason: 'كسر');

    expect((string) $damage->total_cost)->toBe('12.0000')
        ->and($damage->items->sole()->qty)->toBe('-3.0000')
        ->and(($this->onHand)($this->main))->toBe('7.0000');

    app(VoidStockAdjustment::class)->handle($damage, 'خطأ إدخال');

    expect(($this->onHand)($this->main))->toBe('10.0000')
        ->and($damage->refresh()->voided_at)->not->toBeNull()
        ->and(fn () => app(VoidStockAdjustment::class)->handle($damage, 'again'))->toThrow(DomainException::class);
    assertStockConsistent();
});

it('never lets an adjustment push stock negative, even with overselling on', function () {
    $s = app(InventorySettings::class);
    $s->allow_overselling = true;
    $s->save();

    expect(fn () => app(CreateStockAdjustment::class)->handle($this->main->id, A::Decrease, [new StockLineData($this->variation->id, $this->piece->id, '1')]))
        ->toThrow(InsufficientStock::class);
    expect(StockAdjustment::query()->count())->toBe(0); // whole document rolled back
});

it('will not void opening stock that was partly issued', function () {
    $opening = app(CreateStockAdjustment::class)->handle($this->main->id, A::Opening, [new StockLineData($this->variation->id, $this->piece->id, '10', unitCost: '4')]);
    app(CreateStockAdjustment::class)->handle($this->main->id, A::Decrease, [new StockLineData($this->variation->id, $this->piece->id, '1')]);

    expect(fn () => app(VoidStockAdjustment::class)->handle($opening, 'x'))->toThrow(StockAlreadyConsumed::class);
    expect($opening->refresh()->voided_at)->toBeNull();
});

it('rejects a unit that does not belong to the product', function () {
    $kg = Unit::query()->where('name', 'كيلوجرام')->firstOrFail();

    expect(fn () => app(CreateStockAdjustment::class)->handle($this->main->id, A::Opening, [new StockLineData($this->variation->id, $kg->id, '1', unitCost: '1')]))
        ->toThrow(DomainException::class);
});

it('moves stock between branches at the original cost', function () {
    app(CreateStockAdjustment::class)->handle($this->main->id, A::Opening, [new StockLineData($this->variation->id, $this->piece->id, '12', unitCost: '3')]);

    $transfer = app(CreateStockTransfer::class)->handle($this->main->id, $this->branch->id,
        [new StockLineData($this->variation->id, $this->carton->id, '0.5')], sendNow: true);

    expect($transfer->status)->toBe(StockTransferStatus::InTransit)
        ->and((string) $transfer->total_cost)->toBe('18.0000')
        ->and(($this->onHand)($this->main))->toBe('6.0000')
        ->and(($this->onHand)($this->branch))->toBe('0.0000');

    app(ReceiveStockTransfer::class)->handle($transfer);

    expect($transfer->refresh()->status)->toBe(StockTransferStatus::Received)
        ->and(($this->onHand)($this->branch))->toBe('6.0000')
        ->and(StockLevel::query()->where('location_id', $this->branch->id)->value('avg_cost'))->toBe('3.0000')
        ->and(fn () => app(CancelStockTransfer::class)->handle($transfer))->toThrow(DomainException::class);
    assertStockConsistent();
});

it('returns in-transit stock to the source when a transfer is cancelled', function () {
    app(CreateStockAdjustment::class)->handle($this->main->id, A::Opening, [new StockLineData($this->variation->id, $this->piece->id, '5', unitCost: '3')]);
    $transfer = app(CreateStockTransfer::class)->handle($this->main->id, $this->branch->id, [new StockLineData($this->variation->id, $this->piece->id, '5')], sendNow: true);

    app(CancelStockTransfer::class)->handle($transfer);

    expect($transfer->refresh()->status)->toBe(StockTransferStatus::Cancelled)
        ->and(($this->onHand)($this->main))->toBe('5.0000');
    assertStockConsistent();
});

it('refuses a transfer to the same branch', function () {
    expect(fn () => app(CreateStockTransfer::class)->handle($this->main->id, $this->main->id, [new StockLineData($this->variation->id, $this->piece->id, '1')]))
        ->toThrow(DomainException::class);
});

it('rebuilds a corrupted stock level cache from the ledger', function () {
    app(CreateStockAdjustment::class)->handle($this->main->id, A::Opening, [new StockLineData($this->variation->id, $this->piece->id, '8', unitCost: '2')]);
    StockLevel::query()->update(['qty_on_hand' => 999]);

    Artisan::call('stock:rebuild');

    expect(($this->onHand)($this->main))->toBe('8.0000')
        ->and(Artisan::call('stock:rebuild', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('match');
});
