<?php

use App\Domain\Catalog\Models\Unit;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Sales\Models\Sale;
use App\Filament\Admin\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\Admin\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\Admin\Resources\StockAdjustments\Pages\ViewStockAdjustment;
use App\Filament\Admin\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\Admin\Resources\StockLevels\Pages\ListStockLevels;
use App\Filament\Admin\Resources\StockTransfers\Pages\CreateStockTransfer;
use App\Filament\Admin\Resources\StockTransfers\Pages\ViewStockTransfer;
use App\Models\User;
use App\Support\DocumentNumber;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->admin = User::query()->firstOrFail();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    $this->main = Location::query()->firstOrFail();
    $this->branch = Location::query()->create(['code' => 'B2', 'name' => 'فرع 2']);
    $this->piece = Unit::query()->where('name', 'قطعة')->firstOrFail();
    $this->variation = makeVariation(['unit_id' => $this->piece->id], ['purchase_price' => 7]);
});

function openingStock(int $locationId, int $variationId, int $unitId, float $qty, float $cost): void
{
    livewire(CreateStockAdjustment::class)
        ->fillForm(['location_id' => $locationId, 'type' => 'opening', 'adjusted_at' => now()->toDateTimeString(),
            'items' => [['variation_id' => $variationId, 'unit_id' => $unitId, 'qty' => $qty, 'unit_cost' => $cost]]])
        ->call('create')
        ->assertHasNoFormErrors();
}

it('posts opening stock from the form and shows it everywhere', function () {
    openingStock($this->main->id, $this->variation->id, $this->piece->id, 10, 5);

    $adjustment = StockAdjustment::query()->sole();
    expect((string) app(StockLedger::class)->onHand($this->variation->id, $this->main->id))->toBe('10.0000');

    livewire(ListStockAdjustments::class)->assertOk()->assertCanSeeTableRecords([$adjustment]);
    livewire(ViewStockAdjustment::class, ['record' => $adjustment->id])->assertOk()->assertSee($adjustment->number);
    livewire(ListStockLevels::class)->assertOk()->assertSee($this->variation->product->name);
});

it('totals the quantity on hand under the stock list, for the filtered rows', function () {
    $other = makeVariation(['unit_id' => $this->piece->id], ['purchase_price' => 3]);
    openingStock($this->main->id, $this->variation->id, $this->piece->id, 10, 5);
    openingStock($this->main->id, $other->id, $this->piece->id, 2.5, 3);
    openingStock($this->branch->id, $other->id, $this->piece->id, 4, 3);

    livewire(ListStockLevels::class)->assertOk()->assertSee('إجمالي الكمية')->assertSee('16.50')
        ->filterTable('location_id', $this->main->id)->assertSee('12.50');
});

it('shows insufficient stock as a notification and saves nothing', function () {
    livewire(CreateStockAdjustment::class)
        ->fillForm(['location_id' => $this->main->id, 'type' => 'damage', 'adjusted_at' => now()->toDateTimeString(),
            'items' => [['variation_id' => $this->variation->id, 'unit_id' => $this->piece->id, 'qty' => 3]]])
        ->call('create')
        ->assertNotified();

    expect(StockAdjustment::query()->count())->toBe(0);
});

it('voids an adjustment from its page', function () {
    openingStock($this->main->id, $this->variation->id, $this->piece->id, 4, 5);
    $adjustment = StockAdjustment::query()->sole();

    livewire(ViewStockAdjustment::class, ['record' => $adjustment->id])
        ->callAction('void', ['reason' => 'خطأ'])
        ->assertNotified();

    expect($adjustment->refresh()->voided_at)->not->toBeNull()
        ->and((string) app(StockLedger::class)->onHand($this->variation->id, $this->main->id))->toBe('0.0000');
});

it('sends and receives a transfer through the pages', function () {
    openingStock($this->main->id, $this->variation->id, $this->piece->id, 10, 5);

    livewire(CreateStockTransfer::class)
        ->fillForm(['from_location_id' => $this->main->id, 'to_location_id' => $this->branch->id, 'transfer_date' => now()->toDateTimeString(),
            'send_now' => true, 'items' => [['variation_id' => $this->variation->id, 'unit_id' => $this->piece->id, 'qty' => 4]]])
        ->assertSee('10.00') // what the sending branch has of the line's item
        ->call('create')
        ->assertHasNoFormErrors();

    $transfer = StockTransfer::query()->sole();
    expect($transfer->status)->toBe(StockTransferStatus::InTransit);

    livewire(ViewStockTransfer::class, ['record' => $transfer->id])->callAction('receive');

    expect($transfer->refresh()->status)->toBe(StockTransferStatus::Received)
        ->and((string) app(StockLedger::class)->onHand($this->variation->id, $this->branch->id))->toBe('4.0000');
});

it('lets a storekeeper work only in their own location', function () {
    $keeper = User::factory()->create();
    $keeper->assignRole('storekeeper');
    $keeper->locations()->attach($this->branch->id);
    $this->actingAs($keeper);

    expect(StockAdjustmentResource::userLocationOptions())->toBe([$this->branch->id => 'فرع 2']);

    // a crafted request for another branch is refused
    livewire(CreateStockAdjustment::class)
        ->fillForm(['location_id' => $this->main->id, 'type' => 'increase', 'adjusted_at' => now()->toDateTimeString(),
            'items' => [['variation_id' => $this->variation->id, 'unit_id' => $this->piece->id, 'qty' => 1, 'unit_cost' => 1]]])
        ->call('create');
    expect(StockAdjustment::query()->count())->toBe(0);

    // opening stock is not offered without opening_stock.manage... storekeeper has it, cashier does not
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $this->actingAs($cashier);
    $this->get(StockAdjustmentResource::getUrl())->assertForbidden();
});

it('shows sale and purchase numbers in the item card without tripping strict models', function () {
    $sale = new Sale;
    $sale->setRawAttributes(['invoice_no' => 'INV-9']);
    $purchase = new Purchase;
    $purchase->setRawAttributes(['number' => 'PU-3']);
    $draft = new Sale;
    $draft->setRawAttributes(['draft_no' => 'DR-1']);

    expect(DocumentNumber::of($sale))->toBe('INV-9')
        ->and(DocumentNumber::of($purchase))->toBe('PU-3')
        ->and(DocumentNumber::of($draft))->toBe('DR-1')
        ->and(DocumentNumber::of(null))->toBeNull();
});
