<?php

use App\Game\GameEngine;
use App\Game\GameState;
use App\Game\InvalidMoveException;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells, and swaps
// out any randomly-dealt Catacombs/Beds of poison for a neutral
// Location.

function resolveEventRelicForTest2(GameState $state, string $actorRole, string $relicKey, array $move = []): array
{
    $reflection = new ReflectionMethod(GameEngine::class, 'resolveEventRelic');
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $state, $actorRole, $relicKey, $move);
}

test('Invigoration costs 7 Health for +2 actions when explicitly taken', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state, $events] = resolveEventRelicForTest2($state, 'wanderer_a', 'invigoration', ['take_invigoration' => true]);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(3)
        ->and($state->wanderers['wanderer_a']['actions_remaining'])->toBe(5) // 3 + 2
        ->and(collect($events)->pluck('type'))->toContain('wanderer_actions_adjusted');
});

test('Invigoration does nothing unless explicitly taken', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = resolveEventRelicForTest2($state, 'wanderer_a', 'invigoration');

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and($state->wanderers['wanderer_a']['actions_remaining'])->toBe(3);
});

test('Bazaar lets a Wanderer tuck a Relic and immediately refunds the action', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'bazaar'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'golem-whistle'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $actionsBefore = $state->wanderers['wanderer_a']['actions_remaining'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'tuck_relic_at_bazaar', 'relic_key' => 'golem-whistle',
    ]);

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and($state->board['1,0']['tucked'])->toBe(['golem-whistle'])
        ->and($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBefore)
        ->and(collect($events)->pluck('type'))->toContain('relic_tucked');
});

test('Bazaar\'s tuck action requires actually being on the Bazaar', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['holding' => ['golem-whistle']])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'tuck_relic_at_bazaar', 'relic_key' => 'golem-whistle']))
        ->toThrow(InvalidMoveException::class);
});

test('Purification pools clears Shame, Scar, and Bound Relics from the mat', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'purification-pools'];
    $state = $state->with(['board' => $board]);
    $equipped = [
        'body' => 'shame_token', 'mind' => 'scar_token',
        'hand_left' => 'burial-robes', 'hand_right' => 'golem-whistle', 'bag' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'cleanse_at_purification_pools']);

    expect($state->wanderers['wanderer_a']['equipped'])->toBe([
        'body' => null, 'mind' => null, 'hand_left' => null, 'hand_right' => 'golem-whistle', 'bag' => null,
    ])
        ->and($state->trashedRelics)->toBe(['burial-robes'])
        ->and(collect($events)->pluck('type'))->toContain('wanderer_shame_removed')
        ->and(collect($events)->pluck('type'))->toContain('wanderer_scar_removed')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Purification pools requires actually being there', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel'];
    $state = $state->with(['board' => $board]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'cleanse_at_purification_pools']))
        ->toThrow(InvalidMoveException::class);
});

test('Golem sanctuary tucks a healing Relic used there instead of trashing or keeping it equipped', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'golem-sanctuary'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'divine-healing-oil'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-healing-oil']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(8)
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and($state->board['1,0']['tucked'])->toBe(['divine-healing-oil'])
        ->and($state->trashedRelics)->toBe([]) // tucked, not trashed
        ->and(collect($events)->pluck('type'))->toContain('relic_tucked');
});

test('Golem sanctuary does not tuck a non-healing Relic used there', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'golem-sanctuary'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'golem-whistle'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-whistle']);

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBe('golem-whistle')
        ->and($state->board['1,0']['tucked'])->toBe([]);
});

test('House of Cryptids tucks a Golem card when the Wanderer phase ends with everyone on the Golem\'s space', function () {
    $state = startedGame(); // both Wanderers already share the Golem's hub cell
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'house-of-cryptids'];
    $state = $state->with(['board' => $board, 'golemSkipped' => true]); // isolate from the real Golem phase's own card consumption
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $drawCountBefore = count($state->golem['draw_pile']);

    // Only ending the *last* Wanderer's turn triggers the Wanderer
    // phase actually ending.
    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);
    [$state, $events] = GameEngine::apply($state, 'wanderer_b', ['type' => 'end_wanderer_turn']);

    expect($state->board['0,0']['tucked'])->toHaveCount(1)
        ->and(count($state->golem['draw_pile']))->toBe($drawCountBefore - 1)
        ->and(collect($events)->pluck('type'))->toContain('golem_card_tucked');
});

test('House of Cryptids returns its 4 tucked cards to the Golem discard pile on the 4th', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'house-of-cryptids', 'tucked' => ['storm', 'spew', 'scar']];
    $state = $state->with(['board' => $board, 'golemSkipped' => true]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);
    [$state, $events] = GameEngine::apply($state, 'wanderer_b', ['type' => 'end_wanderer_turn']);

    expect($state->board['0,0']['tucked'])->toBe([])
        ->and($state->golem['discard_pile'])->toContain('storm', 'spew', 'scar')
        ->and(collect($events)->pluck('type'))->toContain('house_of_cryptids_cards_returned');
});

test('House of Cryptids does nothing if any Wanderer is off the Golem\'s space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'house-of-cryptids'];
    $state = $state->with(['board' => $board, 'golemSkipped' => true]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [2, 0]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);
    [$state] = GameEngine::apply($state, 'wanderer_b', ['type' => 'end_wanderer_turn']);

    expect($state->board['0,0']['tucked'])->toBe([]);
});

test('Exile can only be entered by a Wanderer with 3 Shame tokens', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'location_key' => 'exile'];
    $state = $state->with(['board' => $board]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']))
        ->toThrow(InvalidMoveException::class, 'Exile');

    $equipped = ['body' => 'shame_token', 'mind' => 'shame_token', 'hand_left' => 'shame_token', 'hand_right' => null, 'bag' => null];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([2, 0]);
});

test('The game ends immediately if every Wanderer is in Exile at the end of a round before the last', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'exile'];
    $state = $state->with(['board' => $board, 'golemSkipped' => true, 'round' => 3]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);
    [$state, $events] = GameEngine::apply($state, 'wanderer_b', ['type' => 'end_wanderer_turn']);

    expect($state->phase)->toBe('finished')
        ->and(collect($events)->pluck('type'))->toContain('game_finished');
});

test('Exile does not end the game early if any Wanderer is elsewhere', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'exile'];
    $state = $state->with(['board' => $board, 'golemSkipped' => true, 'round' => 3]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [2, 0]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);
    [$state] = GameEngine::apply($state, 'wanderer_b', ['type' => 'end_wanderer_turn']);

    expect($state->phase)->toBe('emperor')
        ->and($state->round)->toBe(4);
});

test('Library counts as producing every resource the city already produces, for build-cost purposes', function () {
    $state = startedGame();
    $board = $state->board;
    // Pin every starting cell to a non-Engineering-producing Location
    // first — otherwise the random deal could already satisfy
    // Builder's site's cost on its own, making the first assertion
    // below flaky.
    foreach (array_keys($board) as $key) {
        $board[$key] = [...$board[$key], 'location_key' => 'artists-hovel'];
    }
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'library'];
    $state = $state->with(['board' => $board, 'locationHand' => [...$state->locationHand, 'scriptorium']]);

    // Scriptorium costs "Engineering" — nothing on the board actually
    // produces that yet, so building next to the Library alone
    // shouldn't work...
    expect(fn () => GameEngine::apply($state, 'emperor', [
        'type' => 'play_location', 'location_key' => 'scriptorium', 'cell' => [1, -1],
    ]))->toThrow(InvalidMoveException::class);

    // ...but once something in the city actually produces Engineering,
    // the Library effectively does too, anywhere adjacent to it.
    $board = $state->board;
    $board['2,1'] = [...$board['2,1'], 'location_key' => 'builders-site'];
    $state = $state->with(['board' => $board]);

    [$state] = GameEngine::apply($state, 'emperor', [
        'type' => 'play_location', 'location_key' => 'scriptorium', 'cell' => [1, -1],
    ]);

    expect($state->board['1,-1']['location_key'])->toBe('scriptorium');
});
