<?php

use App\Domain\CRM\Models\Contact;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Purchasing\Actions\CreatePurchaseOrder;
use App\Domain\Purchasing\Data\PurchaseLineData;
use App\Domain\Purchasing\Enums\PurchaseOrderStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Filament\Admin\Resources\Customers\CustomerResource;
use App\Filament\Admin\Resources\Customers\Pages as CustomerPages;
use App\Filament\Admin\Resources\PaymentVouchers\Pages\ListPaymentVouchers;
use App\Filament\Admin\Resources\PaymentVouchers\Pages\ViewPaymentVoucher;
use App\Filament\Admin\Resources\PurchaseOrders\Pages as OrderPages;
use App\Filament\Admin\Resources\PurchaseReturns\Pages as ReturnPages;
use App\Filament\Admin\Resources\Purchases\Pages as PurchasePages;
use App\Filament\Admin\Resources\Suppliers\Pages as SupplierPages;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->item = makeVariation([], ['purchase_price' => 5]);
});

it('creates a supplier with an opening balance from the form', function () {
    livewire(SupplierPages\CreateSupplier::class)
        ->fillForm(['name' => 'شركة النيل', 'type' => 'supplier', 'mobile' => '0100', 'opening_balance' => 750, 'opening_balance_date' => now()->toDateString()])
        ->call('create')->assertHasNoFormErrors();

    $supplier = Contact::query()->where('name', 'شركة النيل')->firstOrFail();
    expect($supplier->code)->toStartWith('C')->and($supplier->balance)->toBe('-750.0000');

    livewire(SupplierPages\ListSuppliers::class)->assertCanSeeTableRecords([$supplier]);
    livewire(CustomerPages\ListCustomers::class)->assertCanNotSeeTableRecords([$supplier]);
    livewire(SupplierPages\ViewSupplier::class, ['record' => $supplier->id])->assertOk()->assertSee(__('We owe them'));
    livewire(SupplierPages\SupplierStatement::class, ['record' => $supplier->id])->assertOk()->assertSee('750.00');
});

it('creates a purchase with immediate payment from the form', function () {
    $supplier = makeSupplier();

    livewire(PurchasePages\CreatePurchase::class)
        ->fillForm([
            'supplier_id' => $supplier->id, 'location_id' => $this->location->id, 'purchase_date' => now()->toDateTimeString(), 'status' => 'received',
            'items' => [['variation_id' => $this->item->id, 'unit_id' => $this->item->product->unit_id, 'qty' => 10, 'unit_cost' => 6, 'discount_value' => 0]],
            'shipping_charges' => 10, 'paid_now' => 30, 'payment_method_id' => cashMethodId(),
        ])
        ->call('create')->assertHasNoFormErrors();

    $purchase = Purchase::query()->sole();
    expect((string) $purchase->total)->toBe('70.0000')
        ->and($purchase->payment_status)->toBe(PaymentStatus::Partial)
        ->and((string) app(StockLedger::class)->onHand($this->item->id, $this->location->id))->toBe('10.0000');

    livewire(PurchasePages\ListPurchases::class)->assertCanSeeTableRecords([$purchase]);
    livewire(PurchasePages\ViewPurchase::class, ['record' => $purchase->id])->assertOk()
        ->callAction('addPayment', ['amount' => 40, 'payment_method_id' => cashMethodId(), 'paid_at' => now()->toDateTimeString()]);
    expect($purchase->refresh()->payment_status)->toBe(PaymentStatus::Paid);

    livewire(ListPaymentVouchers::class)->assertOk();
    livewire(ViewPaymentVoucher::class, ['record' => $purchase->allocations()->first()->payment_id])->assertOk();
});

it('prefills a purchase from an order and closes the order', function () {
    $supplier = makeSupplier();
    $order = app(CreatePurchaseOrder::class)->handle($supplier->id, $this->location->id,
        [new PurchaseLineData($this->item->id, $this->item->product->unit_id, '8', '5')], sent: true);
    livewire(OrderPages\ViewPurchaseOrder::class, ['record' => $order->id])->assertOk();

    livewire(PurchasePages\CreatePurchase::class, ['purchaseOrderId' => $order->id])
        ->assertSchemaStateSet(['supplier_id' => $supplier->id])
        ->set('data.purchase_date', now()->toDateTimeString())
        ->call('create')->assertHasNoFormErrors();

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Received);
});

it('returns part of a purchase from the return page', function () {
    $purchase = makePurchase(makeSupplier(), [[$this->item, 10, 5]]);
    $item = $purchase->items->sole();

    $page = livewire(ReturnPages\CreatePurchaseReturn::class, ['purchaseId' => $purchase->id])
        ->assertSchemaStateSet(['purchase_id' => $purchase->id]);
    $key = array_key_first($page->get('data.purchase_lines'));
    expect($page->get("data.purchase_lines.{$key}.returnable"))->toEqual(10);

    $page->set("data.purchase_lines.{$key}.qty", 3)->call('create')->assertHasNoFormErrors();

    $return = PurchaseReturn::query()->sole();
    expect((string) $return->total)->toBe('15.0000')
        ->and((string) app(StockLedger::class)->onHand($this->item->id, $this->location->id))->toBe('7.0000');
    livewire(ReturnPages\ViewPurchaseReturn::class, ['record' => $return->id])->assertOk();
});

it('receives a customer payment as an advance from the customer page', function () {
    $customer = makeCustomer();

    livewire(CustomerPages\ViewCustomer::class, ['record' => $customer->id])
        ->callAction('paymentIn', ['amount' => 100, 'payment_method_id' => cashMethodId(), 'paid_at' => now()->toDateTimeString(), 'auto_allocate' => true])
        ->assertNotified();

    expect($customer->refresh()->balance)->toBe('-100.0000'); // credit in the customer's favour
});

it('limits "own contacts only" users to their assigned customers', function () {
    $mine = makeCustomer();
    $other = makeCustomer();
    $user = User::factory()->create();
    $user->givePermissionTo(['admin.access', 'customers.view_own']);
    $mine->users()->attach($user->id);
    $this->actingAs($user);

    livewire(CustomerPages\ListCustomers::class)->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$other]);
    $this->get(CustomerResource::getUrl('view', ['record' => $other]))->assertNotFound();
});

it('never lets the walk-in customer be deleted', function () {
    $walkIn = Contact::query()->where('is_default', true)->firstOrFail();

    expect(CustomerResource::canDelete($walkIn))->toBeFalse()
        ->and(CustomerResource::canEdit($walkIn))->toBeTrue();
});
