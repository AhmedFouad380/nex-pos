<?php

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Catalog\Models\Category;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Pos\Pages\PointOfSale;
use App\Models\User;
use App\Settings\PosSettings;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    Filament::setCurrentPanel('pos');
    $this->location = Location::query()->firstOrFail();
    $this->cashier = User::factory()->create();
    $this->cashier->assignRole('cashier');
    $this->cashier->locations()->attach($this->location->id);
    $this->actingAs($this->cashier);
    $this->tea = makeVariation([], ['purchase_price' => 6, 'sell_price' => 10, 'sell_price_inc_tax' => 10]);
    $this->tea->product->locations()->attach($this->location->id);
    makePurchase(makeSupplier(), [[$this->tea, 10, 6]]);
    $this->cart = fn (array $line = []) => ['customer_id' => null, 'lines' => [['variation_id' => $this->tea->id, 'qty' => 2, ...$line]]];
});

it('asks to open a shift first, then opens one on the branch', function () {
    $page = livewire(PointOfSale::class)->assertOk()->assertSee(__('Open a shift'))->assertSee($this->location->name);
    expect($page->instance()->shiftBranches())->toHaveKey($this->location->id)
        ->and($page->instance()->shiftBranches()[$this->location->id]['busy'])->toBeNull();

    $page->call('openShift', $this->location->id, 150);
    $session = CashSession::query()->where('user_id', $this->cashier->id)->where('status', CashSessionStatus::Open)->sole();
    expect($session->opening_float)->toBe('150.0000')->and($session->cashRegister->location_id)->toBe($this->location->id);
});

it('keeps one shift per branch and says who has it open', function () {
    $colleague = User::factory()->create(['name' => 'زميل الوردية']);
    $colleague->assignRole('cashier');
    $colleague->locations()->attach($this->location->id);
    openSession($colleague);

    $page = livewire(PointOfSale::class);
    expect($page->instance()->shiftBranches()[$this->location->id]['busy'])->toBe('زميل الوردية')
        ->and($page->instance()->openShift($this->location->id, 0)['error'] ?? null)->toContain('زميل الوردية');
});

it('gives every branch exactly one drawer', function () {
    $branch = Location::query()->create(['name' => 'فرع جديد', 'code' => 'NEW']);
    expect(CashRegister::query()->where('location_id', $branch->id)->count())->toBe(1);

    // drawers from an old system: the used one stays, unused ones go, ones with history are switched off
    $used = CashRegister::query()->create(['location_id' => $branch->id, 'name' => 'درج قديم']);
    $old = CashRegister::query()->create(['location_id' => $branch->id, 'name' => 'درج 2']);
    CashSession::query()->create(['cash_register_id' => $used->id, 'user_id' => $this->cashier->id,
        'status' => CashSessionStatus::Open, 'opened_at' => now(), 'opening_float' => 0]);
    CashSession::query()->create(['cash_register_id' => $old->id, 'user_id' => $this->cashier->id,
        'status' => CashSessionStatus::Closed, 'opened_at' => now()->subDay(), 'closed_at' => now()->subDay(), 'opening_float' => 0]);

    $this->artisan('cash:branch-drawers')->assertSuccessful();

    expect(CashRegister::query()->where('location_id', $branch->id)->where('is_active', true)->pluck('id')->all())->toBe([$used->id])
        ->and($used->refresh()->name)->toBe('فرع جديد')
        ->and(CashRegister::query()->whereKey($old->id)->value('is_active'))->toBeFalse()
        ->and(CashRegister::query()->where('location_id', $branch->id)->count())->toBe(2); // the empty one was deleted
});

it('loads the catalog and sells through checkout', function () {
    openSession($this->cashier);
    $page = livewire(PointOfSale::class)->assertOk()->assertSee(__('Search or scan barcode…'));

    $boot = $page->instance()->bootstrap();
    expect(collect($boot['catalog'])->firstWhere('id', $this->tea->id)['stock'])->toEqual(10)
        ->and($boot['can']['editPrice'])->toBeFalse();

    $result = $page->instance()->checkout(($this->cart)(), [cash(50)]);

    expect($result)->not->toHaveKey('error')
        ->and($result['sale']['total'])->toEqual(20)
        ->and($result['sale']['change'])->toEqual(30)
        ->and($result['receipt_url'])->toContain('/pos/receipt/')
        ->and((string) app(StockLedger::class)->onHand($this->tea->id, $this->location->id))->toBe('8.0000');

    $this->get($result['receipt_url'])->assertOk()->assertSee($result['sale']['invoice_no']);
});

it('ignores a custom price or discount from a cashier without permission', function () {
    openSession($this->cashier);
    $page = livewire(PointOfSale::class);

    $result = $page->instance()->checkout(($this->cart)(['price' => 1, 'discount_type' => 'percent', 'discount_value' => 90]), [cash(20)]);

    expect($result['sale']['total'])->toEqual(20); // catalog price, no discount
});

it('returns business errors to the screen instead of crashing', function () {
    openSession($this->cashier);
    $page = livewire(PointOfSale::class);

    expect($page->instance()->checkout(($this->cart)(), [])['error'])->toBe(__('sales.errors.walk_in_must_pay'))
        ->and(Sale::query()->count())->toBe(0);
});

it('suspends, recalls and finishes a sale', function () {
    openSession($this->cashier);
    $page = livewire(PointOfSale::class);

    $parked = $page->instance()->park(($this->cart)(), 'suspended');
    expect($parked['sale']['number'])->toBe('DR-00001');
    expect($page->instance()->parkedSales())->toHaveCount(1);

    $recalled = $page->instance()->recall($parked['sale']['id']);
    expect($recalled['lines'][0]['qty'])->toEqual(2);

    $page->instance()->checkout(($this->cart)(['qty' => 3]), [cash(30)]);
    expect(Sale::query()->sole()->status)->toBe(SaleStatus::Final)->and(Sale::query()->sole()->total)->toBe('30.0000');
});

it('records cash out and closes the shift with a report', function () {
    $this->cashier->givePermissionTo('cash_register.view_expected');
    $session = openSession($this->cashier, 100);
    $page = livewire(PointOfSale::class);
    $page->instance()->checkout(($this->cart)(), [cash(20)]);

    expect($page->instance()->cashMovement('out', 500, 'x'))->toHaveKey('error'); // more than the drawer holds
    $page->instance()->cashMovement('out', 50, 'بنك');
    expect($page->instance()->shiftSummary()['expected_cash'])->toBe('70.0000');

    $closed = $page->instance()->closeShift(70);
    expect($closed['difference'])->toEqual(0)->and($session->refresh()->status)->toBe(CashSessionStatus::Closed);
});

it('keeps people without POS access out', function () {
    $user = User::factory()->create();
    $user->assignRole('storekeeper');
    $this->actingAs($user);

    $this->get(PointOfSale::getUrl())->assertForbidden();
});

it('refuses to sell more than is in stock', function () {
    openSession($this->cashier);
    $page = livewire(PointOfSale::class);

    expect($page->instance()->bootstrap()['allowOverselling'])->toBeFalse();

    $result = $page->instance()->checkout(($this->cart)(['qty' => 11]), [cash(110)]);

    expect($result['error'])->toContain($this->tea->fullName())
        ->and(Sale::query()->count())->toBe(0)
        ->and((string) app(StockLedger::class)->onHand($this->tea->id, $this->location->id))->toBe('10.0000');
});

it('shows a point of sale button in the admin top bar only to people who can sell', function () {
    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $this->actingAs($manager)->get('/admin')->assertOk()->assertSee(PointOfSale::getUrl(panel: 'pos'), false);

    $storekeeper = User::factory()->create();
    $storekeeper->assignRole('storekeeper');
    $this->actingAs($storekeeper)->get('/admin')->assertOk()->assertDontSee(PointOfSale::getUrl(panel: 'pos'), false);
});

it('renders the cashier screen with icons, payment buttons and shortcut hints', function () {
    openSession($this->cashier);

    $this->get(PointOfSale::getUrl())->assertOk()
        ->assertSee('pos-pay-btn is-cash', false)->assertSee('pos-success', false)->assertSee('<svg', false)
        ->assertSee(__('Sale completed'))->assertSee(__('Search or scan barcode…'));
});

it('puts items of a sub-category under their main category chip', function () {
    $parent = Category::query()->create(['name' => 'مشروبات']);
    $child = Category::query()->create(['name' => 'شاي', 'parent_id' => $parent->id]);
    $this->tea->product->update(['category_id' => $child->id]);
    openSession($this->cashier);

    $boot = livewire(PointOfSale::class)->instance()->bootstrap();
    expect(collect($boot['catalog'])->firstWhere('id', $this->tea->id)['category_id'])->toBe($parent->id);
});

it('links back to the admin dashboard only for people who may open it', function () {
    openSession($this->cashier);
    $dashboard = Dashboard::getUrl(panel: 'admin');
    $this->get(PointOfSale::getUrl())->assertOk()->assertDontSee($dashboard, false);

    $manager = User::factory()->create();
    $manager->assignRole('manager');
    $manager->locations()->attach($this->location->id);
    $this->flushSession();
    $this->actingAs($manager);
    $this->get(PointOfSale::getUrl())->assertOk()->assertSee($dashboard, false); // top-bar button, even before a shift is open
});

it('lets the cashier edit their own profile', function () {
    $this->get('/pos/profile')->assertOk();

    livewire(EditProfile::class)
        ->fillForm(['name' => 'كاشير الوردية الصباحية'])
        ->call('save')->assertHasNoFormErrors();

    expect($this->cashier->refresh()->name)->toBe('كاشير الوردية الصباحية');
});

it('shows the cost of a cart line only to users who may see purchase prices', function () {
    openSession($this->cashier);
    $cost = fn () => collect(livewire(PointOfSale::class)->instance()->bootstrap()['catalog'])->firstWhere('id', $this->tea->id)['cost'];

    $this->cashier->revokePermissionTo('products.view_purchase_price');
    expect($this->cashier->can('products.view_purchase_price'))->toBeFalse()->and($cost())->toBeNull();

    $this->cashier->givePermissionTo('products.view_purchase_price');
    expect($cost())->toEqual(6.0); // the branch's average cost
});

it('closes a cashier shift blind and tells the managers whether the drawer matched', function () {
    $session = openSession($this->cashier, 100);
    $page = livewire(PointOfSale::class);
    $page->instance()->checkout(($this->cart)(), [cash(20)]);
    $boot = $page->instance()->bootstrap();
    expect($boot['can']['viewSales'])->toBeFalse()->and($boot['can']['seeExpected'])->toBeFalse();

    // no amounts reach the cashier's browser, only the number of invoices
    expect($page->instance()->shiftSummary())->toBe(['blind' => true, 'sales_count' => 1])
        ->and(fn () => $page->instance()->recentSales())->toThrow(HttpException::class);

    $result = $page->instance()->closeShift(110); // 120 expected: 10 short
    expect($result)->toBe(['closed' => true])
        ->and((float) $session->refresh()->difference)->toBe(-10.0);

    $admin = User::query()->where('email', config('newpos.seed_admin.email'))->firstOrFail();
    $notice = $admin->notifications()->latest()->first();
    expect($notice)->not->toBeNull()
        ->and($notice->data['title'])->toContain($this->cashier->name)->toContain('عجز 10.00')
        ->and($notice->data['body'])->toContain('120.00')->toContain('110.00')->toContain('1 بإجمالي 20.00')
        ->and($this->cashier->notifications()->count())->toBe(0);
});

it('shows the expected cash and the shift invoices to users allowed to', function () {
    $this->cashier->givePermissionTo(['cash_register.view_expected', 'pos.view_sales']);
    openSession($this->cashier, 100);
    $page = livewire(PointOfSale::class);
    $page->instance()->checkout(($this->cart)(), [cash(20)]);

    expect($page->instance()->shiftSummary()['expected_cash'])->toBe('120.0000')
        ->and($page->instance()->recentSales())->toHaveCount(1)
        ->and($page->instance()->closeShift(120))->toMatchArray(['closed' => true, 'difference' => 0.0]);
});

it('points to the branches that have an item that is out of stock here', function () {
    $maadi = Location::query()->create(['name' => 'فرع المعادي', 'code' => 'MAA']);
    $closed = Location::query()->create(['name' => 'فرع مقفول', 'code' => 'CLS', 'is_active' => false]);
    $coffee = makeVariation(['name' => 'بن'], ['purchase_price' => 50, 'sell_price' => 80, 'sell_price_inc_tax' => 80]);
    $coffee->product->locations()->attach($this->location->id);
    makePurchase(makeSupplier(), [[$coffee, 12, 50]], ['locationId' => $maadi->id]);
    makePurchase(makeSupplier(), [[$coffee, 3, 50]], ['locationId' => $closed->id]);
    openSession($this->cashier);
    $page = livewire(PointOfSale::class);

    $item = collect($page->instance()->bootstrap()['catalog'])->firstWhere('id', $coffee->id);
    expect($item['stock'])->toEqual(0)->and($item['elsewhere'])->toBeTrue()
        ->and(collect($page->instance()->bootstrap()['catalog'])->firstWhere('id', $this->tea->id)['elsewhere'])->toBeFalse()
        ->and($page->instance()->otherBranches($coffee->id))->toBe([['name' => 'فرع المعادي', 'qty' => 12.0]]);
});

it('refuses an edited price or discount below cost', function () {
    $this->cashier->givePermissionTo(['pos.edit_price', 'pos.edit_discount']);
    app(PosSettings::class)->fill(['allow_price_edit' => true, 'allow_discount' => true])->save();
    openSession($this->cashier);
    $page = livewire(PointOfSale::class);
    $sell = fn (array $line) => $page->instance()->checkout(($this->cart)($line), [cash(50)]);

    // cost is 6: the cashier is told, without the cost itself
    $tooLow = $sell(['price' => 5]);
    expect($tooLow['error'] ?? null)->toContain('أقل من التكلفة')->not->toContain('6.00')
        ->and($sell(['discount_type' => 'percent', 'discount_value' => 50])['error'] ?? null)->toContain('أقل من التكلفة')
        ->and($sell(['price' => 7]))->not->toHaveKey('error')
        ->and($sell(['discount_type' => 'fixed', 'discount_value' => 3]))->not->toHaveKey('error'); // 10 − 3 = 7

    $this->cashier->givePermissionTo('products.view_purchase_price');
    expect($sell(['price' => 5])['error'] ?? null)->toContain('6.00');

    $this->cashier->givePermissionTo('pos.override_limits'); // manager's approval
    expect($sell(['price' => 5]))->not->toHaveKey('error');
});

it('refuses an invoice discount that takes the sale below cost', function () {
    $this->cashier->givePermissionTo('pos.edit_discount');
    app(PosSettings::class)->fill(['allow_discount' => true])->save();
    openSession($this->cashier);
    $page = livewire(PointOfSale::class);
    // 2 × 10 = 20 for a cost of 2 × 6 = 12
    $sell = fn (array $discount) => $page->instance()->checkout([...($this->cart)(), ...$discount], [cash(50)]);

    expect($sell(['discount_type' => 'fixed', 'discount_value' => 9])['error'] ?? null)->toContain('خصم الفاتورة')
        ->and($sell(['discount_type' => 'percent', 'discount_value' => 50])['error'] ?? null)->toContain('خصم الفاتورة')
        ->and($sell(['discount_type' => 'fixed', 'discount_value' => 8]))->not->toHaveKey('error'); // 12 = cost

    $this->cashier->givePermissionTo('pos.override_limits');
    expect($sell(['discount_type' => 'percent', 'discount_value' => 50]))->not->toHaveKey('error');
});

it('puts the shift-closed notification in the bell even without a queue worker', function () {
    Queue::fake(); // nothing queued would ever run
    $session = openSession($this->cashier, 100);
    livewire(PointOfSale::class)->instance()->closeShift(100);

    $admin = User::query()->where('email', config('newpos.seed_admin.email'))->firstOrFail();
    expect($session->refresh()->status)->toBe(CashSessionStatus::Closed)
        ->and($admin->notifications()->count())->toBe(1);
    Queue::assertNothingPushed();
});
