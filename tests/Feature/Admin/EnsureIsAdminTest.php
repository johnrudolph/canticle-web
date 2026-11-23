<?php

use App\Models\Cards\Location;
use App\Models\User;

test('a non-admin user is rejected from the admin area', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

test('a guest (unauthenticated) visitor is rejected from the admin area', function () {
    $this->get('/admin')->assertRedirectToRoute('login');
});

test('an admin user can reach the admin dashboard and a card list', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    Location::create(['key' => 'test-location', 'name' => 'Test Location']);

    $this->actingAs($admin)->get('/admin')->assertOk();
    $this->actingAs($admin)->get('/admin/locations')->assertOk();
});
