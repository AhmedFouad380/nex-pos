<?php

use App\Models\User;

beforeEach(fn () => seedBaseData());

it('redirects guests to the login page', function (string $path) {
    $this->get($path)->assertRedirect("{$path}/login");
})->with(['/admin', '/pos']);

it('renders both login pages in Arabic RTL', function (string $path) {
    $this->get("{$path}/login")->assertOk()->assertSee('dir="rtl"', false);
})->with(['/admin', '/pos']);

it('lets the super admin into both panels', function (string $path) {
    $this->actingAs(User::query()->firstOrFail())->followingRedirects()->get($path)->assertOk(); // /pos → /pos/sell
})->with(['/admin', '/pos']);

it('lets a cashier into POS but not the admin panel', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $this->actingAs($cashier)->followingRedirects()->get('/pos')->assertOk();
    $this->actingAs($cashier)->get('/admin')->assertForbidden();
});

it('blocks deactivated users everywhere', function () {
    $user = User::factory()->create(['is_active' => false]);
    $user->assignRole('manager');

    $this->actingAs($user)->get('/admin')->assertForbidden();
});
