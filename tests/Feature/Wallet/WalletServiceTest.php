<?php

use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Actions\ConfirmWalletTransaction;
use App\Domain\Wallet\Actions\IngestWalletMessage;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Enums\WalletTarget;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Models\WalletServiceRate;
use App\Filament\Admin\Resources\Wallets\ServiceRates\Pages\ManageWalletServiceRates;
use App\Filament\Admin\Resources\Wallets\Transactions\Pages\ViewWalletTransaction;
use App\Models\User;
use App\Support\Decimal;
use App\Support\DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

use function Pest\Livewire\livewire;

const SVC_IN = "تم استلام مبلغ 1500 جنيه من رقم 01090755510 المسجل بإسم Mohsen A Mahmoud على رقم محفظتك  01099638383.\nرصيدك الحالي: 41100.14 جنيه\nتاريخ العملية: 00:05 26-11-23\nرقم العملية: 026077094210";

const SVC_OUT = "تم تحويل 655 جنيه لرقم 01097746217 مصاريف الخدمة 1 جنيه رصيد حسابك فى فودافون كاش الحالي 4.52.\nتاريخ العملية 17:20 26-11-23 :\nرقم العملية 025851509705 :";

beforeEach(function () {
    seedBaseData();
    $this->admin = User::query()->firstOrFail();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->device = WalletDevice::query()->create(['name' => 'موبايل الفرع', 'location_id' => $this->location->id]);
    $this->wallet = Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'خط الفرع', 'phone' => '01099638383',
        'location_id' => $this->location->id]);
    WalletServiceRate::query()->create(['location_id' => $this->location->id, 'transfer_percent' => 1, 'transfer_min' => 5,
        'withdraw_percent' => 1, 'withdraw_min' => 20]);
    $this->ingest = fn (string $body) => app(IngestWalletMessage::class)->handle($this->device, $body, 'VF-Cash')[0];
    $this->balance = fn (int $account) => Decimal::toDb(DB::table('journal_lines')->where('account_id', $account)->sum(DB::raw('debit - credit')));
});

it('takes the branch commission: a percentage, never below the minimum', function () {
    $rate = WalletServiceRate::query()->sole();
    expect((string) $rate->commission(PaymentDirection::Out, '655'))->toBe('6.55')     // 1% = 6.55 > 5
        ->and((string) $rate->commission(PaymentDirection::Out, '200'))->toBe('5.00')    // 1% = 2 < 5
        ->and((string) $rate->commission(PaymentDirection::In, '1500'))->toBe('20.00');  // 1% = 15 < 20
});

it('records a withdrawal: the customer takes the amount less the commission from the drawer', function () {
    $session = openSession(null, 5000);
    $tx = ($this->ingest)(SVC_IN);

    $tx = app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Service, 0, $this->admin);

    expect($tx->status)->toBe(Status::Confirmed)
        ->and($tx->commission)->toBe('20.0000')
        ->and($tx->cash_session_id)->toBe($session->id)
        ->and($tx->payment->direction)->toBe(PaymentDirection::Out)
        ->and($tx->payment->amount)->toBe('1480.0000')
        ->and($tx->commissionPayment->amount)->toBe('20.0000');
    expect((string) app(CashSessionSummary::class)->expectedCash($session))->toBe('3520.0000'); // 5000 − 1480

    $income = Account::query()->where('sub_type', AccountSubType::WalletCommission)->sole();
    $walletAccount = $tx->commissionPayment->account_id;
    expect(($this->balance)($income->id))->toBe('-20.0000')          // income: credit 20
        ->and(($this->balance)($walletAccount))->toBe('1500.0000');  // the whole 1500 came into the wallet
});

it('records a transfer: the customer pays the amount and the commission into the drawer', function () {
    $session = openSession(null, 0);
    $tx = ($this->ingest)(SVC_OUT);
    expect($tx->wallet_id)->toBe($this->wallet->id); // the branch's only line

    $tx = app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Service, 0, $this->admin);

    expect($tx->commission)->toBe('6.5500')
        ->and($tx->payment->direction)->toBe(PaymentDirection::In)
        ->and($tx->payment->amount)->toBe('655.0000')
        ->and($tx->commissionPayment->amount)->toBe('6.5500')
        ->and($tx->fee_expense_id)->not->toBeNull(); // the provider's 1 EGP fee is still an expense
    expect((string) app(CashSessionSummary::class)->expectedCash($session))->toBe('661.5500');
    expect(($this->balance)($tx->payment->counter_account_id))->toBe('-656.0000'); // the wallet: 655 sent + 1 fee
});

it('needs an open shift, and the permission to change the commission', function () {
    $tx = ($this->ingest)(SVC_IN);
    expect(fn () => app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Service, 0, $this->admin))
        ->toThrow(DomainException::class, __('wallet.errors.no_open_shift'));

    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $cashier->locations()->attach($this->location->id);
    $this->actingAs($cashier);
    openSession($cashier);
    expect(fn () => app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Service, 0, $cashier, commission: 5))
        ->toThrow(DomainException::class, __('wallet.errors.not_allowed'));
    expect(app(ConfirmWalletTransaction::class)->handle($tx, WalletTarget::Service, 0, $cashier)->commission)->toBe('20.0000');
});

it('confirms the service from the screen and manages the branch rates', function () {
    openSession();
    $tx = ($this->ingest)(SVC_IN);

    livewire(ViewWalletTransaction::class, ['record' => $tx->id])
        ->mountAction('confirm')->assertActionDataSet(['target_type' => 'service', 'commission' => '20.00'])
        ->setActionData(['commission' => 30])->callMountedAction()->assertHasNoActionErrors();
    expect($tx->refresh()->commission)->toBe('30.0000')->and(Payment::query()->count())->toBe(2);

    livewire(ManageWalletServiceRates::class)->assertOk()->assertCanSeeTableRecords(WalletServiceRate::all());
});
