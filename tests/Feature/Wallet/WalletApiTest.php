<?php

use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\DevicePairing;
use App\Filament\Admin\Resources\Wallets\Devices\Pages\ManageWalletDevices;
use App\Filament\Admin\Resources\Wallets\Templates\Pages\ManageWalletMessageTemplates;
use App\Filament\Admin\Resources\Wallets\Transactions\Pages\ListWalletTransactions;
use App\Filament\Admin\Resources\Wallets\Transactions\Pages\ViewWalletTransaction;
use App\Filament\Admin\Resources\Wallets\Wallets\Pages\ManageWallets;
use App\Models\User;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

const API_SMS = "تم استلام مبلغ 300.00 جنيه من 01096660484؛\nالمسجل بإسم أحمد\nعلى رقم محفظتك 01099638383 بتاريخ 10:05 26-11-17.\nرصيدك الحالي: 900.00 جنيه\nرقم العملية: 111222333444";

beforeEach(function () {
    seedBaseData();
    $this->location = Location::query()->firstOrFail();
    $this->admin = User::query()->firstOrFail();
    $this->admin->forceFill(['pin' => '1234'])->save();
    Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'فودافون', 'phone' => '01099638383', 'location_id' => $this->location->id]);
    $this->pair = function (): array {
        $code = app(DevicePairing::class)->issue($this->location, $this->admin)['code'];

        return $this->postJson('/api/wallet/pair', ['code' => $code, 'device_name' => 'Samsung A15', 'platform' => 'android'])
            ->assertCreated()->json();
    };
});

it('pairs once with a QR code and returns the config', function () {
    $code = app(DevicePairing::class)->issue($this->location, $this->admin)['code'];
    $response = $this->postJson('/api/wallet/pair', ['code' => strtolower($code), 'device_name' => 'Samsung A15'])->assertCreated();

    expect($response->json('device.location.id'))->toBe($this->location->id)
        ->and($response->json('senders'))->toBe(['VF-Cash'])
        ->and(collect($response->json('users'))->pluck('id')->all())->toContain($this->admin->id)
        ->and($response->json('token'))->toBeString();

    $this->postJson('/api/wallet/pair', ['code' => $code, 'device_name' => 'تاني'])->assertStatus(422); // one-time
});

it('takes messages, lists them pending and confirms with the employee PIN', function () {
    $token = ($this->pair)()['token'];
    $api = fn () => $this->withToken($token);

    $api()->postJson('/api/wallet/messages', ['messages' => [
        ['sender' => 'VF-Cash', 'body' => API_SMS, 'received_at' => now()->toIso8601String(), 'sim_slot' => 0],
        ['sender' => 'VF-Cash', 'body' => API_SMS], // the same SMS twice in one batch
    ]])->assertOk()->assertJsonPath('results.0.new', true)->assertJsonPath('results.1.new', false);

    $list = $api()->getJson('/api/wallet/transactions')->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['amount'])->toBe('300.0000')->and($list[0]['counterparty_name'])->toBe('أحمد');
    $id = $list[0]['id'];

    $customer = makeCustomer(['mobile' => '01096660484']);
    $api()->getJson("/api/wallet/transactions/{$id}")->assertOk()->assertJsonPath('data.suggestions.0.id', $customer->id);
    $api()->getJson('/api/wallet/targets?type=contact&q='.urlencode($customer->name))->assertOk()->assertJsonPath('data.0.id', $customer->id);

    $api()->postJson("/api/wallet/transactions/{$id}/confirm", ['user_id' => $this->admin->id, 'pin' => '0000',
        'target_type' => 'contact', 'target_id' => $customer->id])->assertStatus(422);
    $api()->postJson("/api/wallet/transactions/{$id}/confirm", ['user_id' => $this->admin->id, 'pin' => '1234',
        'target_type' => 'contact', 'target_id' => $customer->id])
        ->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.confirmed_by', $this->admin->name);

    expect($customer->refresh()->balance)->toBe('-300.0000'); // credit on account
    $api()->getJson('/api/wallet/transactions')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects with a reason and refuses other branches, revoked phones and no token', function () {
    $paired = ($this->pair)();
    $device = WalletDevice::query()->findOrFail($paired['device']['id']);
    $other = Location::query()->create(['name' => 'فرع 2', 'code' => 'B2']);
    $foreign = WalletTransaction::query()->create(['location_id' => $other->id, 'body' => 'x', 'body_hash' => 'h', 'received_at' => now(), 'status' => 'pending']);

    $this->withToken($paired['token'])->getJson("/api/wallet/transactions/{$foreign->id}")->assertNotFound();

    $this->withToken($paired['token'])->postJson('/api/wallet/messages', ['messages' => [['sender' => 'VF-Cash', 'body' => API_SMS]]]);
    $id = WalletTransaction::query()->where('location_id', $this->location->id)->value('id');
    $this->withToken($paired['token'])->postJson("/api/wallet/transactions/{$id}/reject", ['user_id' => $this->admin->id, 'pin' => '1234',
        'reason' => 'تحويل شخصي'])->assertOk()->assertJsonPath('data.status', 'rejected');

    $this->withToken($paired['token'])->patchJson('/api/wallet/device', ['location_id' => $other->id, 'user_id' => $this->admin->id, 'pin' => '1234'])
        ->assertOk()->assertJsonPath('device.location.id', $other->id);

    app(DevicePairing::class)->revoke($device);
    $this->app['auth']->forgetGuards();
    $this->withToken($paired['token'])->getJson('/api/wallet/config')->assertUnauthorized();
    $this->getJson('/api/wallet/config')->assertUnauthorized();
});

it('shows the wallet screens and confirms from the admin panel', function () {
    // the phone sends first (token only), then someone confirms in the browser
    $token = ($this->pair)()['token'];
    $this->withToken($token)->postJson('/api/wallet/messages', ['messages' => [['sender' => 'VF-Cash', 'body' => API_SMS]]])->assertOk();
    $this->app['auth']->forgetGuards();
    $this->actingAs($this->admin);
    Filament::setCurrentPanel('admin');
    $tx = WalletTransaction::query()->sole();
    $supplier = makeSupplier();

    livewire(ListWalletTransactions::class)->assertOk()->assertCanSeeTableRecords([$tx])
        ->callTableAction('confirm', $tx, ['target_type' => 'contact', 'target_id' => $supplier->id])
        ->assertHasNoTableActionErrors();
    expect($tx->refresh()->payment_id)->not->toBeNull();

    livewire(ViewWalletTransaction::class, ['record' => $tx->id])->assertOk()->assertSee('111222333444');
    livewire(ManageWallets::class)->assertOk()->assertSee('فودافون');
    $devices = livewire(ManageWalletDevices::class)->assertOk()->assertSee('Samsung A15')
        ->callAction('pair', ['location_id' => $this->location->id]);
    $code = $devices->get('pairing.code');
    expect($code)->toBeString();
    $devices->assertActionMounted('showQr');
    $html = (string) $devices->instance()->showQrAction()->getModalContent();
    expect($html)->toContain($code)->toContain('data:image/svg+xml;base64');
    livewire(ManageWalletMessageTemplates::class)->assertOk()->assertSee('فودافون كاش - استلام');
});
