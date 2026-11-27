<?php

use App\Filament\Admin\Resources\Settings\Pages\ManageUsers;
use App\Filament\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    Filament::setCurrentPanel('admin');
});

it('sends a cashier who signs in on the admin login to the cash register', function () {
    $cashier = User::factory()->create(['email' => 'cashier@shop.test', 'password' => '12345678']);
    $cashier->assignRole('cashier');

    livewire(Login::class)
        ->fillForm(['email' => 'cashier@shop.test', 'password' => '12345678'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getPanel('pos')->getUrl());

    expect(auth()->id())->toBe($cashier->id);
});

it('still refuses a user who may open no panel, and a wrong password', function () {
    $nobody = User::factory()->create(['email' => 'nobody@shop.test', 'password' => '12345678']);
    $nobody->assignRole(Role::create(['name' => 'كاشير محل', 'guard_name' => 'web'])); // a role without any access

    // the password is right: say why, instead of "wrong credentials"
    livewire(Login::class)->fillForm(['email' => 'nobody@shop.test', 'password' => '12345678'])
        ->call('authenticate')->assertHasFormErrors(['email'])->assertSee('دخول نقطة البيع');
    expect(auth()->check())->toBeFalse();

    $nobody->update(['is_active' => false]);
    livewire(Login::class)->fillForm(['email' => 'nobody@shop.test', 'password' => '12345678'])
        ->call('authenticate')->assertHasFormErrors(['email'])->assertSee('هذا الحساب موقوف');

    // a wrong password tells nothing about the account
    livewire(Login::class)->fillForm(['email' => 'nobody@shop.test', 'password' => 'wrong-one'])
        ->call('authenticate')->assertHasFormErrors(['email'])->assertDontSee('موقوف');
    livewire(Login::class)->fillForm(['email' => config('newpos.seed_admin.email'), 'password' => 'wrong'])
        ->call('authenticate')->assertHasFormErrors(['email']);
});

it('signs an admin into the admin panel as before', function () {
    livewire(Login::class)
        ->fillForm(['email' => config('newpos.seed_admin.email'), 'password' => config('newpos.seed_admin.password')])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(Filament::getPanel('admin')->getUrl());
});

it('signs in with the username as well as the e-mail', function () {
    $cashier = User::factory()->create(['username' => 'sherif', 'password' => '12345678']);
    $cashier->assignRole('cashier');

    livewire(Login::class)->fillForm(['email' => 'sherif', 'password' => '12345678'])
        ->call('authenticate')->assertHasNoFormErrors();

    expect(auth()->id())->toBe($cashier->id);
});

it('activates login for a user imported from the old system', function () {
    $imported = User::factory()->create(['username' => 'sherif-9', 'email' => 'legacy-user-9@import.invalid', 'allow_login' => false]);
    $imported->assignRole('cashier');

    livewire(Login::class)->fillForm(['email' => 'sherif-9', 'password' => 'new-pass1'])
        ->call('authenticate')->assertHasFormErrors(['email']);

    $this->actingAs(User::query()->where('email', config('newpos.seed_admin.email'))->firstOrFail());
    livewire(ManageUsers::class)
        ->filterTable('cannot_log_in')->assertCanSeeTableRecords([$imported])
        ->callTableAction('activateLogin', $imported, ['username' => 'sherif', 'password' => 'new-pass1', 'pin' => '4321'])
        ->assertHasNoTableActionErrors();
    auth()->logout();

    livewire(Login::class)->fillForm(['email' => 'sherif', 'password' => 'new-pass1'])
        ->call('authenticate')->assertHasNoFormErrors()->assertRedirect(Filament::getPanel('pos')->getUrl());
    expect($imported->refresh()->allow_login)->toBeTrue();
});
