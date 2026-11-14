<?php

use App\Game\GameSetup;
use App\Game\GameStateRepository;
use App\Models\Game;
use App\Models\GameRole;
use App\Models\User;

test('peek_location_deck reveals the real deck contents only in that one response', function () {
    $user = User::factory()->create();
    $game = Game::factory()->create(['status' => 'active', 'created_by_user_id' => $user->id]);
    GameRole::factory()->create(['game_id' => $game->id, 'role_key' => 'wanderer_a', 'user_id' => $user->id]);
    GameRole::factory()->create(['game_id' => $game->id, 'role_key' => 'wanderer_b', 'user_id' => $user->id]);
    GameRole::factory()->create(['game_id' => $game->id, 'role_key' => 'emperor', 'user_id' => $user->id]);

    $state = GameSetup::create(2);
    $startCell = array_key_first($state->board);
    [$col, $row] = array_map('intval', explode(',', $startCell));
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'an-insight-from-the-future'];
    $state = $state->with([
        'wanderers' => $state->wanderersWith('wanderer_a', [
            'equipped' => $equipped, 'cell' => [$col, $row], 'hp' => 10, 'actions_remaining' => 2,
        ]),
        'phase' => 'wanderer',
        'wandererOrder' => ['wanderer_a', 'wanderer_b'],
        'activeWandererIndex' => 0,
    ]);
    GameStateRepository::persist($game, $state);

    $response = $this->actingAs($user)->postJson("/games/{$game->code}/moves", [
        'role_key' => 'wanderer_a',
        'move' => ['type' => 'peek_location_deck', 'relic_key' => 'an-insight-from-the-future'],
    ]);

    $response->assertOk();
    expect($response->json('revealed_location_deck'))->toBe($state->locationDeck);

    // A normal, successful move's response never carries it.
    $response = $this->actingAs($user)->postJson("/games/{$game->code}/moves", [
        'role_key' => 'wanderer_a',
        'move' => ['type' => 'rest'],
    ]);

    $response->assertOk();
    expect($response->json('revealed_location_deck'))->toBeNull();
});
