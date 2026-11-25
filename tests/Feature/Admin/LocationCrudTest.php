<?php

use App\Models\Cards\Branch;
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

test('creating a location with costs and produces computes the emoji/boolean fields', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/locations', [
        'name' => 'Computed Fields Place',
        'print' => false,
        'rule' => 'Does something.',
        'relics' => '6 🎨',
        'produces' => 'Art',
        'costs' => ['Magic', 'Solidarity'],
    ])->assertRedirect();

    $location = Location::where('name', 'Computed Fields Place')->first();

    expect($location->produces_emoji)->toBe('🎨')
        ->and($location->cost_1)->toBe('Magic')
        ->and($location->cost_2)->toBe('Solidarity')
        ->and($location->cost_3)->toBe('')
        ->and($location->cost_emoji)->toBe('✨🤝')
        ->and($location->has_cost)->toBeTrue()
        ->and($location->produces_anything)->toBeTrue()
        ->and($location->has_back_text)->toBeTrue()
        ->and($location->has_relics)->toBeTrue()
        ->and($location->has_specific_relics)->toBeFalse()
        ->and($location->relic_names)->toBe('');
});

test('relic_names is derived from Relics whose location points at this Location, not typed in directly', function () {
    $admin = adminUser();
    \App\Models\Cards\Relic::create(['key' => 'specific-relic-b', 'name' => 'Specific Relic B', 'location' => 'Relic-Linked Place']);
    \App\Models\Cards\Relic::create(['key' => 'specific-relic-a', 'name' => 'Specific Relic A', 'location' => 'Relic-Linked Place']);

    $this->actingAs($admin)->post('/admin/locations', [
        'name' => 'Relic-Linked Place',
        // Submitting relic_names should have no effect — it's never
        // accepted from the request at all.
        'relic_names' => 'Ignored Input',
    ])->assertRedirect();

    $location = Location::where('name', 'Relic-Linked Place')->first();

    expect($location->relic_names)->toBe('Specific Relic A, Specific Relic B')
        ->and($location->has_specific_relics)->toBeTrue();
});

test('every Branch case is accepted as a produces value — regression test for drift between this validator and the enum', function () {
    $admin = adminUser();

    foreach (Branch::cases() as $branch) {
        $this->actingAs($admin)->post('/admin/locations', [
            'name' => "Every Branch Test ({$branch->value})",
            'produces' => $branch->value,
        ])->assertSessionDoesntHaveErrors('produces');
    }
});

test('an invalid location produces/cost branch is rejected', function () {
    $admin = adminUser();

    $this->actingAs($admin)->post('/admin/locations', [
        'name' => 'Bad Branch Place',
        'produces' => 'NotARealBranch',
    ])->assertSessionHasErrors('produces');

    $this->actingAs($admin)->post('/admin/locations', [
        'name' => 'Bad Cost Place',
        'costs' => ['NotARealBranch'],
    ])->assertSessionHasErrors('costs.0');
});

test('the locations index includes a branch summary of Relic and Location counts', function () {
    $admin = adminUser();

    // The test DB is seeded with the real card set, so assert against
    // the baseline + what this test adds, not an absolute count.
    $relicsBefore = \App\Models\Cards\Relic::where('symbol', '🎨')->count();
    $locationsBefore = Location::where('produces', 'Art')->count();

    \App\Models\Cards\Relic::create(['key' => 'art-relic-a', 'name' => 'Art Relic A', 'symbol' => '🎨']);
    \App\Models\Cards\Relic::create(['key' => 'art-relic-b', 'name' => 'Art Relic B', 'symbol' => '🎨']);
    Location::create(['key' => 'art-place', 'name' => 'Art Place', 'produces' => 'Art']);

    $response = $this->actingAs($admin)->get('/admin/locations');

    $response->assertInertia(fn ($page) => $page
        ->where('branchSummary.relicCounts.Art', $relicsBefore + 2)
        ->where('branchSummary.locationCounts.Art', $locationsBefore + 1)
        ->where('branchSummary.relicCounts.Agriculture', 0)
    );
});

test('GET /admin/locations/{id} renders the dedicated detail page', function () {
    $admin = adminUser();
    $location = Location::create(['key' => 'detail-page-place', 'name' => 'Detail Page Place']);

    $this->actingAs($admin)->get("/admin/locations/{$location->id}")->assertOk();
});
