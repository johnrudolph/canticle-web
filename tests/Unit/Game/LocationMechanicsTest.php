<?php

use App\Game\Board;
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

test('Guide rope does not bring an ineligible Ally into Catacombs, but still brings an eligible one', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'location_key' => 'catacombs'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'was_ever_exhausted' => true,
        'equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'guide-rope'],
    ])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([2, 0])
        ->and($state->wanderers['wanderer_b']['cell'])->toBe([1, 0]); // left behind — never exhausted

    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [1, 0]])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['was_ever_exhausted' => true])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']);

    expect($state->wanderers['wanderer_b']['cell'])->toBe([2, 0]);
});

test('Quantum robes (swap with Ally) is a no-op if either Wanderer would end up in Catacombs without eligibility', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'location_key' => 'catacombs'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'quantum-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [2, 0]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'quantum-robes', 'target_role' => 'wanderer_b']);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([1, 0])
        ->and($state->wanderers['wanderer_b']['cell'])->toBe([2, 0]);
});

test('Teleportation stone will not place an Ally in Catacombs without eligibility', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'location_key' => 'catacombs', 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'teleportation-stone'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'teleportation-stone', 'target_role' => 'wanderer_b', 'cell' => [2, 0],
    ]);

    expect($state->wanderers['wanderer_b']['cell'])->toBe([1, 0]);
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

test('Scriptorium blocks entry unless it has 4 adjacent Locations with Monolith Shards', function () {
    // rules.md 2026-11-24 revision: previously a one-time "move all
    // Shards to the Emperor's pool when built" effect; now an ongoing
    // entry restriction instead.
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'scriptorium'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 1]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $direction = Board::directionBetween([0, 1], [1, 0]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $direction]))
        ->toThrow(InvalidMoveException::class);
});

test('Scriptorium allows entry once it has 4 adjacent Locations with Monolith Shards', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'scriptorium'];
    $neighborKeys = array_map(Board::key(...), Board::occupiedNeighbors([1, 0], $board));
    foreach (array_slice($neighborKeys, 0, 4) as $key) {
        $board[$key] = [...$board[$key], 'infrastructure' => ['monolith_shard']];
    }
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 1]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $direction = Board::directionBetween([0, 1], [1, 0]);
    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $direction]);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([1, 0]);
});

test('Defenders\' Guild tucks an attack Golem card that dealt 0 damage, and returns + gains Prophecy at 4', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'defenders-guild'];
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'maybeTuckAtDefendersGuild');
    $reflection->setAccessible(true);
    $hpBefore = array_map(fn (array $w) => $w['hp'], $state->wanderers);

    [$state, $events] = $reflection->invoke(null, $state, 'charge', $hpBefore);
    expect($state->board['1,0']['tucked'])->toBe(['charge'])
        ->and(collect($events)->pluck('type'))->toContain('golem_card_tucked');

    foreach (['charge', 'charge'] as $cardKey) {
        [$state] = $reflection->invoke(null, $state, $cardKey, $hpBefore);
    }
    expect($state->board['1,0']['tucked'])->toHaveCount(3);

    [$state, $events] = $reflection->invoke(null, $state, 'charge', $hpBefore);
    expect($state->board['1,0']['tucked'])->toBe([])
        ->and($state->golem['discard_pile'])->toContain('charge')
        ->and($state->prophecy)->toBe(2)
        ->and(collect($events)->pluck('type'))->toContain('defenders_guild_cards_returned');
});

test('Defenders\' Guild does not tuck an attack card that actually dealt damage', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'defenders-guild'];
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'maybeTuckAtDefendersGuild');
    $reflection->setAccessible(true);
    $hpBefore = array_map(fn (array $w) => $w['hp'] + 1, $state->wanderers);

    [$state, $events] = $reflection->invoke(null, $state, 'charge', $hpBefore);

    expect($state->board['1,0']['tucked'])->toBe([])
        ->and($events)->toBe([]);
});
