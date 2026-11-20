<?php

use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Foundation\Models\Location;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Filament\Admin\Resources\CashSessions\CashSessionResource;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Resources\Purchases\PurchaseResource;
use App\Filament\Admin\Resources\Sales\SaleResource;
use App\Filament\Admin\Resources\StockLevels\StockLevelResource;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Once;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
});

it('counts what needs attention next to each screen', function () {
    expect(SaleResource::getNavigationBadge())->toBeNull(); // nothing yet → no badge
    Once::flush();

    $tea = makeVariation(['alert_qty' => 5], ['purchase_price' => 6, 'sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$tea, 6, 6]]);                                     // unpaid purchase
    makePurchase(makeSupplier(), [[$tea, 1, 6]], ['status' => PurchaseStatus::Pending]); // another one
    $session = openSession();
    sell([[$tea, 2]], [], ['cashSessionId' => $session->id, 'customerId' => makeCustomer()->id]); // credit sale; 4 left ≤ alert 5
    sell([[$tea, 0.5]], [cash(5)], ['cashSessionId' => $session->id]);                            // paid — not counted
    app(CreateExpense::class)->handle(['location_id' => $this->location->id, 'amount' => 50]);
    Once::flush();

    expect(SaleResource::getNavigationBadge())->toBe('1')
        ->and(SaleResource::getNavigationBadgeTooltip())->toBe(__('Invoices with an amount due'))
        ->and(PurchaseResource::getNavigationBadge())->toBe('2')
        ->and(StockLevelResource::getNavigationBadge())->toBe('1')
        ->and(StockLevelResource::getNavigationBadgeColor())->toBe('danger')
        ->and(CashSessionResource::getNavigationBadge())->toBe('1')
        ->and(ExpenseResource::getNavigationBadge())->toBe('1');

    $this->get('/admin')->assertOk()->assertSee(__('Invoices with an amount due'));
});

it('only counts the locations a user works in', function () {
    $other = Location::query()->create(['name' => 'فرع 2', 'code' => 'B2']);
    $tea = makeVariation();
    makePurchase(makeSupplier(), [[$tea, 3, 6]], ['locationId' => $other->id]);

    $clerk = User::factory()->create();
    $clerk->assignRole('accountant');
    $clerk->revokePermissionTo('locations.access_all');
    $clerk->roles->first()->revokePermissionTo('locations.access_all');
    $clerk->locations()->attach($this->location->id);
    $this->actingAs($clerk->refresh());
    Once::flush();

    expect(PurchaseResource::getNavigationBadge())->toBeNull();
});
