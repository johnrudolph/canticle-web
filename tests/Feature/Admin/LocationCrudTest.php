<?php

use App\Models\Cards\Location;
use App\Models\User;

function adminUser(): User
{
    return User::factory()->create(['is_admin' => true]);
}

test('an admin can create a location and gets a frozen, derived key', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/locations', [
        'name' => 'Brand New Place',
        'print' => false,
        'lore' => 'Some lore',
    ])->assertRedirect();

    $location = Location::where('name', 'Brand New Place')->first();

    expect($location)->not->toBeNull()
        ->and($location->key)->toBe('brand-new-place');
});

test('creating a location whose name collides with an existing key gets a disambiguated suffix', function () {
    $admin = adminUser();
    Location::create(['key' => 'the-pen', 'name' => 'The Pen']);

    $this->actingAs($admin)->post('/admin/locations', [
        'name' => 'The Pen',
        'print' => false,
    ])->assertRedirect();

    expect(Location::where('key', 'the-pen-2')->exists())->toBeTrue();
});

test('updating a location does not change its key even if the name changes', function () {
    $admin = adminUser();
    $location = Location::create(['key' => 'original-key', 'name' => 'Original Name']);

    $this->actingAs($admin)->put("/admin/locations/{$location->id}", [
        'name' => 'A Totally Different Name',
        'print' => true,
    ])->assertRedirect();

    $location->refresh();

    expect($location->key)->toBe('original-key')
        ->and($location->name)->toBe('A Totally Different Name')
        ->and($location->print)->toBeTrue();
});

test('deleting a location requires a reason, soft-deletes it, and creates a deletion comment', function () {
    $admin = adminUser();
    $location = Location::create(['key' => 'doomed-place', 'name' => 'Doomed Place']);

    $this->actingAs($admin)->delete("/admin/locations/{$location->id}", [])
        ->assertSessionHasErrors('reason');

    $this->actingAs($admin)->delete("/admin/locations/{$location->id}", [
        'reason' => 'Replaced by a better location.',
    ])->assertRedirect();

    $location->refresh();

    expect($location->trashed())->toBeTrue();

    $comment = $location->comments()->first();
    expect($comment->kind)->toBe('deletion')
        ->and($comment->body)->toBe('Replaced by a better location.')
        ->and($comment->resolved_at)->toBeNull();
});

test('a non-admin cannot perform any admin card mutation', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $location = Location::create(['key' => 'protected-place', 'name' => 'Protected Place']);

    $this->actingAs($user)->post('/admin/locations', ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($user)->put("/admin/locations/{$location->id}", ['name' => 'Nope'])->assertForbidden();
    $this->actingAs($user)->delete("/admin/locations/{$location->id}", ['reason' => 'nope'])->assertForbidden();
});
