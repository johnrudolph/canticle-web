<?php

use App\Game\Catalog\LocationCatalog;
use App\Models\Game;
use App\Models\GameRole;
use App\Models\User;

function lobbyGameWithAllRolesClaimed(User $host, array $attributes = []): Game
{
    $game = Game::factory()->create([
        'status' => 'lobby',
        'wanderer_count' => 2,
        'created_by_user_id' => $host->id,
        ...$attributes,
    ]);

    GameRole::factory()->create(['game_id' => $game->id, 'role_key' => 'wanderer_a', 'user_id' => $host->id]);
    GameRole::factory()->create(['game_id' => $game->id, 'role_key' => 'wanderer_b', 'user_id' => $host->id]);
    GameRole::factory()->create(['game_id' => $game->id, 'role_key' => 'emperor', 'user_id' => $host->id]);

    return $game;
}

test('starting the game with exclude_advanced_locations keeps every Advanced Location off the board', function () {
    $host = User::factory()->create();
    $game = lobbyGameWithAllRolesClaimed($host, ['exclude_advanced_locations' => true]);

    $this->actingAs($host)->post("/games/{$game->code}/start")->assertRedirect();

    $game->refresh();
    expect($game->status)->toBe('active');

    foreach ($game->state['board'] as $cell) {
        expect(LocationCatalog::find($cell['location_key'])?->isAdvanced)->toBeFalse();
    }
});

test('the Emperor must choose starting Locations before the host can start the game when that option is on', function () {
    $host = User::factory()->create();
    $game = lobbyGameWithAllRolesClaimed($host, ['emperor_picks_starting_locations' => true]);

    $this->actingAs($host)->post("/games/{$game->code}/start")->assertSessionHasErrors('starting_location_keys');

    $game->refresh();
    expect($game->status)->toBe('lobby');
});

test('the Emperor can pick their 6 starting Locations, and the host starting the game uses exactly those', function () {
    $host = User::factory()->create();
    $game = lobbyGameWithAllRolesClaimed($host, ['emperor_picks_starting_locations' => true]);

    $chosen = ['field', 'medicinal-herb-garden', 'artists-hovel', 'bazaar', 'cabalist-inferno', 'defenders-guild'];

    $this->actingAs($host)
        ->post("/games/{$game->code}/starting-locations", ['location_keys' => $chosen])
        ->assertRedirect();

    $game->refresh();
    expect($game->starting_location_keys)->toBe($chosen);

    $this->actingAs($host)->post("/games/{$game->code}/start")->assertRedirect();

    $game->refresh();
    $boardKeys = array_values(array_map(fn ($cell) => $cell['location_key'], $game->state['board']));
    expect($boardKeys)->toEqualCanonicalizing($chosen);
});

test('picking starting Locations rejects a non-is_starter key', function () {
    $host = User::factory()->create();
    $game = lobbyGameWithAllRolesClaimed($host, ['emperor_picks_starting_locations' => true]);

    // 'town-square' is is_starter, but not every Location is — grab one
    // that definitely isn't by excluding every known is_starter key.
    $invalidKey = collect(LocationCatalog::all())
        ->first(fn ($location) => ! $location->isStarter)
        ->key;

    $chosen = ['field', 'medicinal-herb-garden', 'artists-hovel', 'bazaar', 'cabalist-inferno', $invalidKey];

    $this->actingAs($host)
        ->post("/games/{$game->code}/starting-locations", ['location_keys' => $chosen])
        ->assertSessionHasErrors('location_keys');

    $game->refresh();
    expect($game->starting_location_keys)->toBeNull();
});

test('only the Emperor can pick starting Locations', function () {
    $host = User::factory()->create();
    $other = User::factory()->create();
    $game = lobbyGameWithAllRolesClaimed($host, ['emperor_picks_starting_locations' => true]);

    $chosen = ['field', 'medicinal-herb-garden', 'artists-hovel', 'bazaar', 'cabalist-inferno', 'defenders-guild'];

    $this->actingAs($other)
        ->post("/games/{$game->code}/starting-locations", ['location_keys' => $chosen])
        ->assertForbidden();
});
