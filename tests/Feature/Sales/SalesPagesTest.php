<?php

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Sales\Actions\CreateSaleReturn;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Filament\Admin\Resources\CashSessions\Pages\ListCashSessions;
use App\Filament\Admin\Resources\CashSessions\Pages\ViewCashSession;
use App\Filament\Admin\Resources\Customers\Pages\CustomerStatement;
use App\Filament\Admin\Resources\SaleReturns\Pages as ReturnPages;
use App\Filament\Admin\Resources\Sales\Pages as SalePages;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->session = openSession(null, 50);
    $this->tea = makeVariation([], ['purchase_price' => 6, 'sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$this->tea, 10, 6]]);
});

it('lists and shows sales, collects a credit sale and links it in the statement', function () {
    $customer = makeCustomer();
    $sale = sell([[$this->tea, 3]], [], ['cashSessionId' => $this->session->id, 'customerId' => $customer->id]);

    livewire(SalePages\ListSales::class)->assertOk()->assertCanSeeTableRecords([$sale]);
    livewire(SalePages\ViewSale::class, ['record' => $sale->id])->assertOk()
        ->callAction('addPayment', ['amount' => 30, 'payment_method_id' => cashMethodId(), 'paid_at' => now()->toDateTimeString()]);

    expect($sale->refresh()->payment_status)->toBe(PaymentStatus::Paid)->and($customer->refresh()->balance)->toBe('0.0000');
    livewire(CustomerStatement::class, ['record' => $customer->id])->assertOk()->assertSee($sale->invoice_no);
});

it('returns a sale from its page with an immediate refund', function () {
    $sale = sell([[$this->tea, 4]], [cash(40)], ['cashSessionId' => $this->session->id]);

    $page = livewire(ReturnPages\CreateSaleReturn::class, ['saleId' => $sale->id])->assertOk();
    $key = array_key_first($page->get('data.lines'));
    $page->set("data.lines.{$key}.qty", 1)->set('data.refund_method_id', cashMethodId())->call('create')->assertHasNoFormErrors();

    $return = SaleReturn::query()->sole();
    expect((string) $return->total)->toBe('10.0000')->and($return->payment_status)->toBe(PaymentStatus::Paid)
        ->and((string) app(StockLedger::class)->onHand($this->tea->id, $this->location->id))->toBe('7.0000');
    livewire(ReturnPages\ViewSaleReturn::class, ['record' => $return->id])->assertOk();
    livewire(ReturnPages\ListSaleReturns::class)->assertCanSeeTableRecords([$return]);
});

it('voids a sale from its page', function () {
    $sale = sell([[$this->tea, 2]], [cash(20)], ['cashSessionId' => $this->session->id]);

    livewire(SalePages\ViewSale::class, ['record' => $sale->id])->callAction('void', ['reason' => 'خطأ']);

    expect($sale->refresh()->voided_at)->not->toBeNull()
        ->and((string) app(StockLedger::class)->onHand($this->tea->id, $this->location->id))->toBe('10.0000');
});

it('shows the shift report and lets a manager close it', function () {
    sell([[$this->tea, 1]], [cash(10)], ['cashSessionId' => $this->session->id]);

    livewire(ListCashSessions::class)->assertOk()->assertCanSeeTableRecords([$this->session]);
    livewire(ViewCashSession::class, ['record' => $this->session->id])->assertOk()->assertSee('60.00')
        ->callAction('close', ['counted' => 60, 'card_slips' => 0]);

    expect($this->session->refresh()->status)->toBe(CashSessionStatus::Closed)->and($this->session->difference)->toBe('0.0000');
});

it('prints a receipt only for people who may see it', function () {
    $sale = sell([[$this->tea, 1]], [cash(10)], ['cashSessionId' => $this->session->id]);
    $this->get(route('pos.receipt', $sale))->assertOk()->assertSee($sale->invoice_no);

    $outsider = User::factory()->create();
    $outsider->assignRole('cashier'); // no location assigned
    $this->actingAs($outsider)->get(route('pos.receipt', $sale))->assertForbidden();

    auth()->logout();
    $this->get(route('pos.receipt', $sale))->assertRedirect(route('filament.pos.auth.login'));
});

it('marks a returned invoice and hides the return button once everything came back', function () {
    $sale = sell([[$this->tea, 4]], [cash(40)], ['cashSessionId' => $this->session->id]);
    $line = $sale->items()->value('id');
    expect($sale->returnState())->toBeNull();
    livewire(SalePages\ViewSale::class, ['record' => $sale->id])->assertActionVisible('return')->assertDontSee(__('Partially returned'));

    $first = app(CreateSaleReturn::class)->handle($sale, [['sale_item_id' => $line, 'qty' => 1]], cashMethodId(), $this->session->id);
    expect($sale->returnState())->toBe('partial');
    livewire(SalePages\ViewSale::class, ['record' => $sale->id])->assertActionVisible('return')
        ->assertSee(__('Partially returned'))->assertSee($first->number);

    app(CreateSaleReturn::class)->handle($sale, [['sale_item_id' => $line, 'qty' => 3]], cashMethodId(), $this->session->id);
    expect($sale->returnState())->toBe('full');
    livewire(SalePages\ViewSale::class, ['record' => $sale->id])->assertActionHidden('return')->assertSee(__('Fully returned'));
});
