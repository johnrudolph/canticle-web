<?php

use App\Game\GameEngine;
use App\Game\InvalidMoveException;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells, and swaps
// out any randomly-dealt Catacombs/Beds of poison for a neutral
// Location so ordinary movement in other tests stays deterministic.

test('Medicinal herb garden heals 5 when a Wanderer ends their turn there', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'medicinal-herb-garden'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_healed');
});

test('Medicinal herb garden does not heal a Wanderer who ends their turn elsewhere', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
});

test('Beds of poison deals 5 damage to any Wanderer entering it, even passing through', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'location_key' => 'beds-of-poison'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 15])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_damaged');
});

test('Catacombs can only be entered by a Wanderer who has been exhausted before', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'location_key' => 'catacombs'];
    $state = $state->with(['board' => $board]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']))
        ->toThrow(InvalidMoveException::class, 'Catacombs');

    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['was_ever_exhausted' => true])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([2, 0]);
});

test('Reaching 0 Health for real sets was_ever_exhausted; Cabalist wraps\' save does not', function () {
    $state = startedGame();

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);

    $plain = $reflection->invoke(null, $state, 'wanderer_a', 20)[0];
    expect($plain->wanderers['wanderer_a']['was_ever_exhausted'])->toBeTrue();

    $equipped = [...$state->wanderers['wanderer_b']['equipped'], 'body' => 'cabalist-wraps'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equipped])]);
    $saved = $reflection->invoke(null, $state, 'wanderer_b', 20)[0];
    expect($saved->wanderers['wanderer_b']['was_ever_exhausted'])->toBeFalse();
});

test('Scriptorium returns every Monolith Shard on the board to the Emperor\'s pool when built', function () {
    $state = startedGame();
    $board = $state->board;
    foreach (array_keys($board) as $key) {
        $board[$key] = [...$board[$key], 'infrastructure' => ['monolith_shard']];
    }
    // Scriptorium costs "Engineering" — pin the hub to a Location that
    // produces it, and build Scriptorium on a new, unbuilt cell
    // adjacent to it (every starting cell is already built). It's not
    // guaranteed to be in the Emperor's randomly-dealt hand either, so
    // force it in there directly.
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'builders-site'];
    $state = $state->with(['board' => $board, 'locationHand' => [...$state->locationHand, 'scriptorium']]);

    $poolBefore = $state->infrastructurePool['monolith_shard'];

    [$state, $events] = GameEngine::apply($state, 'emperor', [
        'type' => 'play_location', 'location_key' => 'scriptorium', 'cell' => [1, -1],
    ]);

    $remainingShards = 0;
    foreach ($state->board as $entry) {
        $remainingShards += count(array_filter($entry['infrastructure'], fn ($i) => $i === 'monolith_shard'));
    }

    expect($remainingShards)->toBe(0)
        ->and($state->infrastructurePool['monolith_shard'])->toBe($poolBefore + 6)
        ->and(collect($events)->pluck('type'))->toContain('infrastructure_returned');
});
