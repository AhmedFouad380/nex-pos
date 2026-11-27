<?php

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Filament\Admin\Resources\PaymentVouchers\Pages\ListPaymentVouchers;
use App\Filament\Admin\Resources\PaymentVouchers\Pages\ViewPaymentVoucher;
use App\Filament\Admin\Resources\ReceiptVouchers\Pages\ListReceiptVouchers;
use App\Filament\Admin\Resources\ReceiptVouchers\Pages\ViewReceiptVoucher;
use App\Filament\Admin\Support\VoucherResource;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->voucher = fn (array $data) => [
        'location_id' => $this->location->id, 'payment_method_id' => cashMethodId(), 'paid_at' => now()->toDateTimeString(), ...$data,
    ];
});

it('receives from a customer on the receipt vouchers page and settles the open invoice', function () {
    $tea = makeVariation([], ['sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$tea, 10, 6]]);
    $customer = makeCustomer();
    $sale = sell([[$tea, 3]], [], ['customerId' => $customer->id, 'cashSessionId' => openSession()->id]);

    livewire(ListReceiptVouchers::class)->assertOk()
        ->callAction('newVoucher', ($this->voucher)(['party' => 'customer', 'contact_id' => $customer->id, 'amount' => 30, 'auto_allocate' => true]))
        ->assertHasNoActionErrors();

    $receipt = Payment::query()->where('direction', PaymentDirection::In)->where('contact_id', $customer->id)->sole();
    expect((float) $sale->refresh()->due_total)->toBe(0.0)
        ->and(VoucherResource::urlFor($receipt))->toContain('receipt-vouchers');

    livewire(ViewReceiptVoucher::class, ['record' => $receipt->id])->assertOk()->assertSee($receipt->number);
});

it('pays a supplier on the payment vouchers page and keeps each page to its own direction', function () {
    $supplier = makeSupplier();

    livewire(ListPaymentVouchers::class)
        ->callAction('newVoucher', ($this->voucher)(['party' => 'supplier', 'contact_id' => $supplier->id, 'amount' => 75]))
        ->assertHasNoActionErrors();

    $paid = Payment::query()->where('contact_id', $supplier->id)->sole();
    expect($paid->direction)->toBe(PaymentDirection::Out);

    livewire(ListPaymentVouchers::class)->assertCanSeeTableRecords([$paid]);
    livewire(ListReceiptVouchers::class)->assertCanNotSeeTableRecords([$paid]);
    livewire(ViewPaymentVoucher::class, ['record' => $paid->id])->assertOk();
    $this->get(VoucherResource::urlFor($paid))->assertOk();
});

it('does not take a supplier on the customer option', function () {
    $supplier = makeSupplier();

    livewire(ListReceiptVouchers::class)
        ->callAction('newVoucher', ($this->voucher)(['party' => 'customer', 'contact_id' => $supplier->id, 'amount' => 10]));

    expect(Payment::query()->count())->toBe(0);

    // a bill paid from the drawer: the other side of the entry is the chosen account
    $rent = Account::query()->where('is_postable', true)->where('type', 'expense')->value('id');
    livewire(ListPaymentVouchers::class)
        ->callAction('newVoucher', ($this->voucher)(['party' => 'account', 'counter_account_id' => $rent, 'amount' => 300]))
        ->assertHasNoActionErrors();
    expect(Payment::query()->sole())->contact_id->toBeNull()->counter_account_id->toBe($rent);
});

it('offers customers and suppliers, plus an account for payments only', function () {
    $employee = makeEmployee('5000');

    livewire(ListPaymentVouchers::class)
        ->callAction('newVoucher', ($this->voucher)(['party' => 'employee', 'contact_id' => $employee->id, 'amount' => 10]))
        ->assertHasActionErrors(['party']);
    livewire(ListReceiptVouchers::class)
        ->callAction('newVoucher', ($this->voucher)(['party' => 'account', 'amount' => 10]))
        ->assertHasActionErrors(['party']);

    expect(Payment::query()->count())->toBe(0);

    // a bill paid from the drawer: the other side of the entry is the chosen account
    $rent = Account::query()->where('is_postable', true)->where('type', 'expense')->value('id');
    livewire(ListPaymentVouchers::class)
        ->callAction('newVoucher', ($this->voucher)(['party' => 'account', 'counter_account_id' => $rent, 'amount' => 300]))
        ->assertHasNoActionErrors();
    expect(Payment::query()->sole())->contact_id->toBeNull()->counter_account_id->toBe($rent);
});

it('lists vouchers only; money taken on an invoice stays on the invoice and its page still opens', function () {
    $tea = makeVariation([], ['sell_price' => 10, 'sell_price_inc_tax' => 10]);
    makePurchase(makeSupplier(), [[$tea, 10, 6]]);
    $customer = makeCustomer();
    sell([[$tea, 2]], [cash(20)], ['customerId' => $customer->id, 'cashSessionId' => openSession()->id]);
    $tendered = Payment::query()->where('direction', PaymentDirection::In)->sole();
    livewire(ListReceiptVouchers::class)
        ->callAction('newVoucher', ($this->voucher)(['party' => 'customer', 'contact_id' => $customer->id, 'amount' => 5]));
    $voucher = Payment::query()->where('direction', PaymentDirection::In)->whereKeyNot($tendered->id)->sole();

    livewire(ListReceiptVouchers::class)->assertCanSeeTableRecords([$voucher])->assertCanNotSeeTableRecords([$tendered]);
    livewire(ViewReceiptVoucher::class, ['record' => $tendered->id])->assertOk();
});

it('opens each voucher page with its own permission, not with view payments', function () {
    $role = Spatie\Permission\Models\Role::create(['name' => 'صرف فقط', 'guard_name' => 'web']);
    $role->givePermissionTo(['admin.access', 'payments.view', 'vouchers.payment']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $this->actingAs($user);

    expect(App\Filament\Admin\Resources\PaymentVouchers\PaymentVoucherResource::canViewAny())->toBeTrue()
        ->and(App\Filament\Admin\Resources\ReceiptVouchers\ReceiptVoucherResource::canViewAny())->toBeFalse();

    $role->givePermissionTo('vouchers.receipt');
    $user->unsetRelation('roles')->forgetCachedPermissions();
    expect(App\Filament\Admin\Resources\ReceiptVouchers\ReceiptVoucherResource::canViewAny())->toBeTrue();
});
