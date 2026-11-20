<?php

use App\Domain\Finance\Models\Payment;
use App\Domain\Foundation\Models\Location;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Domain\Wallet\Services\DevicePairing;
use App\Filament\Admin\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Admin\Resources\Settings\Pages\ListActivities;
use App\Models\User;
use App\Support\Audit\ActivityPresenter;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->admin = User::query()->firstOrFail();
});

it('records who created and changed a record, with only the changed fields', function () {
    $this->actingAs($this->admin);
    $customer = makeCustomer(['name' => 'عميل أول', 'mobile' => '0100']);
    $customer->update(['mobile' => '0111']);

    $created = Activity::query()->forSubject($customer)->where('event', 'created')->sole();
    expect($created->causer?->is($this->admin))->toBeTrue()
        ->and($created->log_name)->toBe('contact')
        ->and($created->getProperty('label'))->toBe('عميل أول')
        ->and($created->getProperty('ip'))->not->toBeNull()
        ->and($created->attribute_changes['attributes'])->not->toHaveKey('updated_at');

    $updated = Activity::query()->forSubject($customer)->where('event', 'updated')->sole();
    expect($updated->attribute_changes->toArray())->toBe(['attributes' => ['mobile' => '0111'], 'old' => ['mobile' => '0100']])
        ->and(ActivityPresenter::summary($updated))->toBe(__('audit.fields.mobile'));
});

it('ignores columns the system updates by itself', function () {
    $this->skipLedgerCheck = true; // the balance is set by hand
    $customer = makeCustomer();
    $before = Activity::query()->count();

    $customer->forceFill(['balance' => 500])->save();

    expect(Activity::query()->count())->toBe($before);
});

it('logs logins, logouts and failed attempts without the password', function () {
    Auth::guard('web')->login($this->admin);
    Auth::guard('web')->logout();
    event(new Failed('web', null, ['username' => 'someone', 'password' => 'secret']));

    $log = Activity::query()->with('causer')->inLog('auth')->orderBy('id')->get();
    expect($log->pluck('event')->all())->toBe(['login', 'logout', 'failed'])
        ->and($log[0]->causer?->is($this->admin))->toBeTrue()
        ->and($log[2]->getProperty('username'))->toBe('someone')
        ->and(json_encode($log[2]->properties))->not->toContain('secret');
});

it('credits the employee whose PIN confirmed a wallet transfer', function () {
    $location = Location::query()->firstOrFail();
    $this->admin->forceFill(['pin' => '1234'])->save();
    Wallet::query()->create(['provider' => WalletProvider::VodafoneCash, 'name' => 'فودافون', 'phone' => '01099638383', 'location_id' => $location->id]);
    $code = app(DevicePairing::class)->issue($location, $this->admin)['code'];
    $token = $this->postJson('/api/wallet/pair', ['code' => $code, 'device_name' => 'Samsung A15'])->json('token');
    $sms = "تم استلام مبلغ 300.00 جنيه من 01096660484؛\nالمسجل بإسم أحمد\nعلى رقم محفظتك 01099638383 بتاريخ 10:05 26-11-17.\nرصيدك الحالي: 900.00 جنيه\nرقم العملية: 111222333444";
    $this->withToken($token)->postJson('/api/wallet/messages', ['messages' => [['sender' => 'VF-Cash', 'body' => $sms]]])->assertOk();
    $tx = WalletTransaction::query()->sole();
    $customer = makeCustomer();

    $this->withToken($token)->postJson("/api/wallet/transactions/{$tx->id}/confirm", ['user_id' => $this->admin->id, 'pin' => '1234',
        'target_type' => 'contact', 'target_id' => $customer->id])->assertOk();

    expect(Activity::query()->forSubject($tx)->where('event', 'created')->value('causer_type'))->toBe('wallet_device');
    $confirmed = Activity::query()->forSubject($tx)->where('event', 'updated')->latest('id')->first();
    expect($confirmed->causer_type)->toBe('user')->and($confirmed->causer_id)->toBe($this->admin->id);
    $payment = Payment::query()->whereKey($tx->refresh()->payment_id)->sole();
    expect(Activity::query()->forSubject($payment)->value('causer_id'))->toBe($this->admin->id);
});

it('shows the activity log and a record history', function () {
    $this->actingAs($this->admin);
    $customer = makeCustomer(['name' => 'عميل السجل']);
    $customer->update(['mobile' => '0122']);
    $activity = Activity::query()->forSubject($customer)->where('event', 'updated')->sole();

    livewire(ListActivities::class)->assertOk()
        ->assertCanSeeTableRecords([$activity])
        ->filterTable('log_name', ['contact'])->assertCanSeeTableRecords([$activity])
        ->filterTable('log_name', ['sale'])->assertCanNotSeeTableRecords([$activity])
        ->resetTableFilters()
        ->mountTableAction('view', $activity)->assertMountedActionModalSee('0122');

    livewire(ViewCustomer::class, ['record' => $customer->getRouteKey()])
        ->mountAction('activityHistory')->assertMountedActionModalSee(['0122', $this->admin->name]);
});

it('hides the activity log from users without the permission', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');
    $this->actingAs($cashier);

    $this->get(route('filament.admin.resources.activity-log.index'))->assertForbidden();
});
