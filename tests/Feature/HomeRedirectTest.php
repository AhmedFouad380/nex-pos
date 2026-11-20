<?php

use App\Models\User;

beforeEach(fn () => seedBaseData());

it('sends a guest to the login page', function () {
    $this->get('/')->assertRedirect('/admin/login');
});

it('sends a signed-in admin to the dashboard', function () {
    $admin = User::factory()->create();
    $admin->assignRole(User::SUPER_ADMIN_ROLE);

    $this->actingAs($admin)->get('/')->assertRedirect('/admin');
});

it('sends a signed-in cashier to the point of sale', function () {
    $cashier = User::factory()->create();
    $cashier->assignRole('cashier');

    $this->actingAs($cashier)->get('/')->assertRedirect('/pos');
});
