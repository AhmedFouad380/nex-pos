<?php

use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Actions\IngestWalletMessage;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Enums\WalletTransactionStatus as Status;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Models\WalletServiceRate;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\WalletLimitAlert;
use App\Filament\Pos\Pages\PointOfSale;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

use function Pest\Livewire\livewire;

const POS_IN = "تم استلام مبلغ 1000 جنيه من رقم 01090755510 المسجل بإسم Mohsen A Mahmoud على رقم محفظتك  01032859600.\nرصيدك الحالي: 21000 جنيه\nتاريخ العملية: 10:05 26-11-23\nرقم العملية: 026077000001";

beforeEach(function () {
    seedBaseData();
    Filament::setCurrentPanel('pos');
    $this->location = Location::query()->firstOrFail();
    $this->cashier = User::factory()->create();
    $this->cashier->assignRole('cashier');
    $this->cashier->locations()->attach($this->location->id);
    $this->actingAs($this->cashier);
    $this->device = WalletDevice::query()->create(['name' => 'موبايل الفرع', 'location_id' => $this->location->id]);
    $this->line1 = Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'خط 1', 'phone' => '01032859600',
        'location_id' => $this->location->id, 'sim_slot' => 1, 'daily_out_limit' => 10000, 'last_balance' => 20000]);
    $this->line2 = Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'خط 2', 'phone' => '01099638383',
        'location_id' => $this->location->id, 'sim_slot' => 2, 'last_balance' => 4000]);
    WalletServiceRate::query()->create(['location_id' => $this->location->id, 'transfer_percent' => 1, 'transfer_min' => 5,
        'withdraw_percent' => 1, 'withdraw_min' => 5]);
});

it('lists the branch messages in the till, suggests a line and confirms a withdrawal through the shift', function () {
    $session = openSession($this->cashier, 2000);
    [$tx] = app(IngestWalletMessage::class)->handle($this->device, POS_IN, 'VF-Cash');

    $page = livewire(PointOfSale::class)->assertOk()->assertSee(__('Wallets'));
    expect($page->instance()->bootstrap()['can']['walletService'])->toBeTrue()
        ->and($page->instance()->bootstrap()['can']['editCommission'])->toBeFalse();

    $wallet = $page->instance()->walletPending();
    expect($wallet['pending'])->toHaveCount(1)
        ->and($wallet['pending'][0])->toMatchArray(['id' => $tx->id, 'out' => false, 'amount' => 1000.0, 'commission' => 10.0, 'line_id' => $this->line1->id])
        ->and($wallet['suggested'])->toBe($this->line1->id) // 10,000 left today vs line 2's 4,000
        ->and(collect($wallet['lines'])->firstWhere('id', $this->line2->id)['available'])->toBe(4000.0);

    // a cashier without the permission cannot change the commission: what they send is ignored
    $result = $page->instance()->confirmWalletService($tx->id, 0);
    expect($result)->toMatchArray(['ok' => true, 'commission' => 10.0])
        ->and($tx->refresh()->status)->toBe(Status::Confirmed)
        ->and((string) app(CashSessionSummary::class)->expectedCash($session))->toBe('1010.0000') // 2000 − (1000 − 10)
        ->and($page->instance()->walletPending()['pending'])->toBe([]);
});

it('hides the wallet button without the permission or without branch lines', function () {
    openSession($this->cashier);
    Wallet::query()->update(['is_active' => false]);
    expect(livewire(PointOfSale::class)->instance()->bootstrap()['can']['walletService'])->toBeFalse();
});

it('warns once when a line reaches 80% of its daily limit, and again when it is used up', function () {
    $admin = User::query()->firstOrFail();
    Cache::flush();
    $send = function (float $amount, string $ref) {
        WalletTransaction::query()->create(['wallet_device_id' => $this->device->id, 'location_id' => $this->location->id, 'wallet_id' => $this->line1->id,
            'body' => $ref, 'body_hash' => hash('sha256', $ref), 'received_at' => now(), 'occurred_at' => now(),
            'provider' => WalletProvider::VodafoneCash, 'direction' => 'out', 'amount' => $amount, 'status' => Status::Pending]);
        app(WalletLimitAlert::class)->check($this->line1);
    };

    $send(5000, 'a');                                   // 50%: nothing
    expect($admin->notifications()->count())->toBe(0);
    $send(3500, 'b');                                   // 85%: warning
    $send(500, 'c');                                    // 90%: already warned
    expect($admin->notifications()->count())->toBe(1);
    $send(1000, 'd');                                   // 100%: used up
    expect($admin->notifications()->count())->toBe(2)
        ->and($admin->notifications()->latest('created_at')->first()->data['title'])->toContain('خط 1');
});
