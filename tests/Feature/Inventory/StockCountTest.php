<?php

use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Actions\CancelStockCount;
use App\Domain\Inventory\Actions\PostStockCount;
use App\Domain\Inventory\Actions\StartStockCount;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Inventory\Enums\StockCountScope;
use App\Domain\Inventory\Enums\StockCountStatus;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Services\StockLedger;
use App\Filament\Admin\Resources\StockCounts\Pages\CreateStockCount;
use App\Filament\Admin\Resources\StockCounts\Pages\ListStockCounts;
use App\Filament\Admin\Resources\StockCounts\Pages\ViewStockCount;
use App\Filament\Admin\Resources\StockCounts\RelationManagers\ItemsRelationManager;
use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Filament\Admin\Resources\StockCounts\Widgets\StockCountSummary;
use App\Models\User;
use App\Support\DomainException;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    $this->location = Location::query()->firstOrFail();
    $this->tea = makeVariation([], ['purchase_price' => 5, 'sell_price' => 10, 'sell_price_inc_tax' => 10]);
    $this->sugar = makeVariation([], ['purchase_price' => 3]);
    $this->rice = makeVariation([], ['purchase_price' => 8]);
    makePurchase(makeSupplier(), [[$this->tea, 10, 5], [$this->sugar, 20, 3], [$this->rice, 4, 8]]);
    $this->onHand = fn ($v) => (string) app(StockLedger::class)->onHand($v->id, $this->location->id);
    $this->line = fn ($count, $v) => $count->items()->where('variation_id', $v->id)->sole();
});

it('lists every stock item of the branch with what it should have', function () {
    $count = app(StartStockCount::class)->handle($this->location->id);

    expect($count->status)->toBe(StockCountStatus::Counting)->and($count->number)->toStartWith('CNT-')
        ->and($count->items()->count())->toBe(3)
        ->and(($this->line)($count, $this->tea)->expected_qty)->toBe('10.0000')
        ->and(($this->line)($count, $this->tea)->unit_cost)->toBe('5.0000')
        ->and(($this->line)($count, $this->tea)->counted_qty)->toBeNull();

    // one open count per branch
    expect(fn () => app(StartStockCount::class)->handle($this->location->id))->toThrow(DomainException::class);
});

it('posts the counted quantities: shortages out, surpluses in, uncounted untouched', function () {
    $count = app(StartStockCount::class)->handle($this->location->id);
    ($this->line)($count, $this->tea)->update(['counted_qty' => 7]);    // 3 missing
    ($this->line)($count, $this->sugar)->update(['counted_qty' => 22]); // 2 extra
    // rice not counted

    $count = app(PostStockCount::class)->handle($count);

    expect($count->status)->toBe(StockCountStatus::Posted)
        ->and(($this->onHand)($this->tea))->toBe('7.0000')
        ->and(($this->onHand)($this->sugar))->toBe('22.0000')
        ->and(($this->onHand)($this->rice))->toBe('4.0000')
        ->and(($this->line)($count, $this->tea)->difference)->toBe('-3.0000')
        ->and($count->stockAdjustment->type)->toBe(StockAdjustmentType::Decrease)
        ->and((string) $count->stockAdjustment->total_cost)->toBe('15.0000')
        ->and($count->surplusAdjustment->type)->toBe(StockAdjustmentType::Increase)
        ->and((string) $count->surplusAdjustment->total_cost)->toBe('6.0000');
    assertStockConsistent();

    expect(fn () => app(PostStockCount::class)->handle($count))->toThrow(DomainException::class);
});

it('applies the counted quantity even if the item sold during the count, and can treat uncounted as zero', function () {
    $count = app(StartStockCount::class)->handle($this->location->id);
    sell([[$this->tea, 2]], [cash(20)], ['cashSessionId' => openSession()->id]); // 10 → 8 while counting
    ($this->line)($count, $this->tea)->update(['counted_qty' => 8]);

    $count = app(PostStockCount::class)->handle($count, uncountedAsZero: true);

    expect(($this->onHand)($this->tea))->toBe('8.0000')
        ->and(($this->line)($count, $this->tea)->difference)->toBe('0.0000')
        ->and(($this->onHand)($this->sugar))->toBe('0.0000')
        ->and(($this->onHand)($this->rice))->toBe('0.0000');
    assertStockConsistent();
});

it('refuses to post an empty count and can cancel it', function () {
    $count = app(StartStockCount::class)->handle($this->location->id, StockCountScope::Full);

    expect(fn () => app(PostStockCount::class)->handle($count))->toThrow(DomainException::class);
    expect(app(CancelStockCount::class)->handle($count)->status)->toBe(StockCountStatus::Cancelled)
        ->and(($this->onHand)($this->tea))->toBe('10.0000');
    // a new count can start once the old one is closed
    expect(app(StartStockCount::class)->handle($this->location->id)->status)->toBe(StockCountStatus::Counting);
});

it('runs a count through the pages: start, fill the sheet, report, post', function () {
    Filament::setCurrentPanel('admin');
    livewire(ListStockCounts::class)->assertOk();
    livewire(CreateStockCount::class)->fillForm(['location_id' => $this->location->id, 'scope' => 'full'])
        ->call('create')->assertHasNoFormErrors();
    $count = StockCount::query()->sole();

    livewire(ViewStockCount::class, ['record' => $count->id])->assertOk()->assertActionVisible('post');
    livewire(StockCountSummary::class, ['record' => $count])->assertOk()->assertSee('0 / 3');

    $sheet = livewire(ItemsRelationManager::class, ['ownerRecord' => $count, 'pageClass' => ViewStockCount::class])->assertOk()
        ->assertCanSeeTableRecords($count->items);
    $tea = ($this->line)($count, $this->tea);
    $sheet->call('updateTableColumnState', 'counted_qty', (string) $tea->id, '6')->assertDispatched('stock-count-updated');
    expect($tea->refresh()->difference)->toBe('-4.0000');
    // search by name, filter the shortages
    $sheet->searchTable($this->tea->product->name)->assertCanSeeTableRecords([$tea])
        ->searchTable('')->filterTable('state', 'shortage')->assertCanSeeTableRecords([$tea])->assertCountTableRecords(1);
    // selected lines match the system, then the rest as well
    $sugar = ($this->line)($count, $this->sugar);
    $sheet->filterTable('state', null)->callTableBulkAction('matchSelected', [$sugar]);
    expect($sugar->refresh()->counted_qty)->toBe('20.0000');
    $sheet->callTableAction('matchRest');
    expect($count->items()->whereNull('counted_qty')->count())->toBe(0);

    $this->get(route('stock-counts.print', ['count' => $count, 'print' => 0]))->assertOk()
        ->assertSee($this->tea->product->name)->assertSee(__('Shortages'));

    livewire(ViewStockCount::class, ['record' => $count->id])->callAction('post', ['uncounted_as_zero' => false])->assertHasNoActionErrors();
    expect($count->refresh()->status)->toBe(StockCountStatus::Posted)->and(($this->onHand)($this->tea))->toBe('6.0000')
        ->and(($this->onHand)($this->sugar))->toBe('20.0000');
    livewire(ViewStockCount::class, ['record' => $count->id])->assertActionHidden('post')->assertActionHidden('cancel');
});

it('lets only holders of the count permission in', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $this->actingAs($cashier);
    $this->get(StockCountResource::getUrl())->assertForbidden();
});
