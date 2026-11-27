<?php

use App\Domain\CashManagement\Actions\CloseCashSession;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\CashManagement\Services\ShiftLink;
use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Domain\Payroll\Actions\GiveAdvance;
use App\Domain\Sales\Actions\CreateSaleReturn;
use App\Filament\Admin\Resources\CashSessions\Pages\ViewCashSession;
use App\Models\User;
use App\Support\DomainException;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->session = openSession(float: 1000);
});

it('puts cash paid from the branch box on the open shift: advance, voucher, expense, return', function () {
    $tea = makeVariation([], ['sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$tea, 10, 6]]);
    $sale = sell([[$tea, 3]], [['payment_method_id' => cashMethodId(), 'amount' => 30]], ['cashSessionId' => $this->session->id]);

    app(GiveAdvance::class)->handle(['contact_id' => makeEmployee('3000')->id, 'location_id' => $this->location->id,
        'amount' => 100, 'payment_method_id' => cashMethodId(), 'advance_date' => now()]);
    app(RecordPayment::class)->handle(new PaymentData(direction: PaymentDirection::Out, paymentMethodId: cashMethodId(), amount: '50',
        contactId: makeSupplier()->id, locationId: $this->location->id, paidAt: now()->startOfDay()));
    $expense = app(CreateExpense::class)->handle(['location_id' => $this->location->id, 'amount' => 20], ['payment_method_id' => cashMethodId()]);
    $return = app(CreateSaleReturn::class)->handle($sale, [['sale_item_id' => $sale->items()->value('id'), 'qty' => 1]], cashMethodId(), reason: 'عيب');

    expect($expense->refresh()->cash_session_id)->toBe($this->session->id)
        ->and($return->refresh()->cash_session_id)->toBe($this->session->id);

    $summary = app(CashSessionSummary::class)->summarize($this->session);
    expect((float) $summary['cash_sales'])->toBe(30.0)
        ->and((float) $summary['cash_refunds'])->toBe(10.0)
        ->and((float) $summary['cash_paid_other'])->toBe(170.0)
        ->and((float) $summary['expected_cash'])->toBe(1000 + 30 - 10 - 170.0);

    $kinds = array_column(app(CashSessionSummary::class)->operations($this->session), 'kind');
    expect($kinds)->toEqualCanonicalizing(['employee_advance', 'payment', 'expense', 'sale_return']);

    livewire(ViewCashSession::class, ['record' => $this->session->id])->assertOk()
        ->assertSee('عمليات الوردية')->assertSee('سلفة موظف')->assertSee('سند صرف')->assertSee('850.00');
});

it('leaves out money of another day, another method or account, and the old-system import', function () {
    $supplier = makeSupplier()->id;
    $box = app(RecordPayment::class)->accountFor(PaymentMethod::query()->findOrFail(cashMethodId()), $this->location->id);
    $other = Account::query()->where('is_postable', true)->whereIn('sub_type', ['cash', 'bank'])->whereKeyNot($box)->value('id')
        ?? Account::query()->create(['code' => '1199', 'name' => 'خزنة أخرى', 'type' => 'asset', 'sub_type' => 'cash', 'is_postable' => true])->id;
    $pay = fn (array $data = []) => app(RecordPayment::class)->handle(new PaymentData(...[
        'direction' => PaymentDirection::Out, 'paymentMethodId' => cashMethodId(), 'amount' => '5', 'contactId' => $supplier,
        'locationId' => $this->location->id, ...$data,
    ]));

    expect($pay(['paidAt' => now()->subDay()])->cash_session_id)->toBeNull()
        ->and(ShiftLink::without(fn () => $pay())->cash_session_id)->toBeNull()
        ->and($pay(['accountId' => $other])->cash_session_id)->toBeNull();

    $card = PaymentMethod::query()->where('type', '!=', 'cash')->first();
    if ($card) {
        expect($pay(['paymentMethodId' => $card->id])->cash_session_id)->toBeNull();
    }
    expect($pay()->cash_session_id)->toBe($this->session->id);
});

it('does not let a cashier move the branch cash while no shift is open there, but a manager may', function () {
    app(CloseCashSession::class)->handle($this->session, 1000);
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $this->actingAs($cashier);
    $pay = fn () => app(RecordPayment::class)->handle(new PaymentData(direction: PaymentDirection::Out, paymentMethodId: cashMethodId(),
        amount: '5', contactId: makeSupplier()->id, locationId: $this->location->id));

    expect($pay)->toThrow(DomainException::class, 'لا توجد وردية مفتوحة اليوم');
    expect(fn () => app(GiveAdvance::class)->handle(['contact_id' => makeEmployee('3000')->id, 'location_id' => $this->location->id,
        'amount' => 100, 'payment_method_id' => cashMethodId()]))->toThrow(DomainException::class);

    $cashier->givePermissionTo('cash_register.close_others'); // a manager / the accountant
    expect($pay()->cash_session_id)->toBeNull();
});
