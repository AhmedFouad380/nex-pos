<?php

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Actions\IngestWalletMessage;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\DevicePairing;
use App\Domain\Wallet\Services\WalletUsage;
use App\Filament\Admin\Resources\Wallets\Wallets\Pages\ManageWallets;
use App\Filament\Admin\Widgets\BranchWallets;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->device = WalletDevice::query()->create(['name' => 'موبايل الفرع', 'location_id' => $this->location->id]);
    $this->line1 = Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'خط 1', 'phone' => '01032859600',
        'location_id' => $this->location->id, 'sim_slot' => 1, 'daily_out_limit' => 60000, 'monthly_out_limit' => 200000, 'last_balance' => 50000]);
    $this->line2 = Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'خط 2', 'phone' => '01099638383',
        'location_id' => $this->location->id, 'sim_slot' => 2, 'last_balance' => 3000]);
    $this->tx = function (Wallet $wallet, PaymentDirection $direction, float $amount, string $at, Status $status = Status::Pending) {
        static $n = 0;
        $n++;

        return WalletTransaction::query()->create(['wallet_device_id' => $this->device->id, 'location_id' => $this->location->id,
            'wallet_id' => $wallet->id, 'body' => "sms {$n}", 'body_hash' => hash('sha256', "sms {$n}"), 'received_at' => $at, 'occurred_at' => $at,
            'provider' => WalletProvider::VodafoneCash, 'direction' => $direction, 'amount' => $amount, 'status' => $status]);
    };
});

it('counts each line\'s use of its limits and what it can still send', function () {
    $now = CarbonImmutable::parse('2026-11-23 18:00');
    ($this->tx)($this->line1, PaymentDirection::Out, 20000, '2026-11-23 10:00');                 // today
    ($this->tx)($this->line1, PaymentDirection::Out, 30000, '2026-11-23 11:00', Status::Confirmed);
    ($this->tx)($this->line1, PaymentDirection::Out, 5000, '2026-11-23 12:00', Status::Rejected);  // not a transfer
    ($this->tx)($this->line1, PaymentDirection::Out, 100000, '2026-11-02 12:00');                // earlier this month
    ($this->tx)($this->line1, PaymentDirection::Out, 90000, '2026-10-30 12:00');                 // last month
    ($this->tx)($this->line1, PaymentDirection::In, 700, '2026-11-23 09:00');

    $usage = app(WalletUsage::class)->for(collect([$this->line1, $this->line2]), $now);

    expect($usage[$this->line1->id]['out_today'])->toBe(50000.0)
        ->and($usage[$this->line1->id]['out_month'])->toBe(150000.0)
        ->and($usage[$this->line1->id]['in_today'])->toBe(700.0)
        // balance 50,000 · daily left 10,000 · monthly left 50,000 → 10,000
        ->and($usage[$this->line1->id]['available_out'])->toBe(10000.0)
        ->and($usage[$this->line1->id]['out_percent'])->toBe(83)
        ->and($usage[$this->line1->id]['level'])->toBe('warning')
        // no limits: only its balance
        ->and($usage[$this->line2->id]['available_out'])->toBe(3000.0)
        ->and($usage[$this->line2->id]['level'])->toBe('success');
});

it('tells which line sent a transfer from the balance in the message', function () {
    // "تم تحويل" names no number of ours; 3000 − 500 − 1 fee = 2499 can only be line 2
    $sms = 'تم تحويل 500 جنيه لرقم 01097746217 مصاريف الخدمة 1 جنيه رصيد حسابك فى فودافون كاش الحالي 2499.'
        ."\nتاريخ العملية 17:20 26-11-23\nرقم العملية 025851509799";
    [$transaction] = app(IngestWalletMessage::class)->handle($this->device, $sms, 'VF-Cash');

    expect($transaction->wallet_id)->toBe($this->line2->id)
        ->and($this->line2->refresh()->last_balance)->toBe('2499.0000');
});

it('shows the branch wallets card on the dashboard and the wallet numbers page', function () {
    ($this->tx)($this->line1, PaymentDirection::Out, 1000, now()->toDateTimeString());

    livewire(BranchWallets::class)->assertOk()->assertSee('خط 1')->assertSee('60,000.00')->assertSee('SIM 2');
    livewire(ManageWallets::class)->assertOk()
        ->mountTableAction('edit', $this->line2)->setTableActionData(['daily_out_limit' => 40000])->callMountedTableAction()->assertHasNoTableActionErrors();
    expect($this->line2->refresh()->daily_out_limit)->toBe('40000.0000');
});

it('keeps one phone per branch: pairing a new one retires the old', function () {
    $code = app(DevicePairing::class)->issue($this->location, User::query()->firstOrFail())['code'];
    $new = app(DevicePairing::class)->redeem($code, 'موبايل جديد')['device'];

    expect($this->device->refresh()->revoked_at)->not->toBeNull()
        ->and($new->revoked_at)->toBeNull();
});
