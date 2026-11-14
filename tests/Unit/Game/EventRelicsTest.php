<?php

use App\Game\GameEngine;
use App\Game\GameState;
use App\Game\InvalidMoveException;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

function resolveEventRelicForTest(GameState $state, string $actorRole, string $relicKey, array $move = []): array
{
    $reflection = new ReflectionMethod(GameEngine::class, 'resolveEventRelic');
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $state, $actorRole, $relicKey, $move);
}

test('Time jump makes the Golem play 1 extra card', function () {
    $state = startedGame();
    $discardCountBefore = count($state->golem['discard_pile']);

    [$state, $events] = resolveEventRelicForTest($state, 'wanderer_a', 'time-jump');

    expect(count($state->golem['discard_pile']))->toBe($discardCountBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('golem_card_resolved');
});

test('Guilty by association and Guilty conscience both grant 1 Shame token', function (string $key) {
    $state = startedGame();

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', $key);

    expect($state->wanderers['wanderer_a']['equipped'])->toContain('shame_token');
})->with(['guilty-by-association', 'guilty-conscience']);

test('Sharp objects grants 1 Scar token', function () {
    $state = startedGame();

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'sharp-objects');

    expect($state->wanderers['wanderer_a']['equipped'])->toContain('scar_token');
});

test('Incarceration blocks movement for the rest of the turn, then clears on the Wanderer\'s next turn', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'incarceration');

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']))
        ->toThrow(InvalidMoveException::class, 'Incarceration');

    // End both Wanderers' turns (which ends the round and resolves the
    // Golem phase), then the new round's Emperor phase, to cycle back
    // to wanderer_a's next turn.
    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);
    [$state] = GameEngine::apply($state, 'wanderer_b', ['type' => 'end_wanderer_turn']);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([2, 0]);
});

test('Buoyant spirit grants +2 actions and Lost in thought removes 1, floored at 0', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'buoyant-spirit');
    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(5); // 3 + 2

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'lost-in-thought');
    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'lost-in-thought');
    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'lost-in-thought');
    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'lost-in-thought');
    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'lost-in-thought');
    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'lost-in-thought');

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(0);
});

test('Time spasm advances an odd round by 1 and reverts an even round by 1', function () {
    $state = startedGame();
    $state = $state->with(['round' => 3]);

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'time-spasm');
    expect($state->round)->toBe(4);

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'time-spasm');
    expect($state->round)->toBe(3);
});

test('Time spasm never moves the round below 1', function () {
    $state = startedGame();
    $state = $state->with(['round' => 2]);

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'time-spasm');

    expect($state->round)->toBe(1);
});

test('Epiphany pulls a random face-down Relic from a chosen different Location', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'relics' => ['burial-robes']];
    $state = $state->with(['board' => $board]);

    [$state, $events] = resolveEventRelicForTest($state, 'wanderer_a', 'epiphany', ['cell' => [0, 0]]);

    expect($state->wanderers['wanderer_a']['holding'])->toContain('burial-robes')
        ->and($state->board['0,0']['relics'])->toBe([])
        ->and(collect($events)->pluck('type'))->toContain('location_searched');
});

test('Epiphany does nothing targeting the caster\'s own space', function () {
    $state = startedGame();

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'epiphany', ['cell' => [1, 0]]);

    expect($state->wanderers['wanderer_a']['holding'])->toBe([]);
});

test('Golem avarice moves the caster\'s whole Relic pile onto the Golem\'s space', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['cell' => [0, 0]])]);
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'relics' => ['burial-robes', 'last-rites']];
    $board['0,0'] = [...$board['0,0'], 'relics' => ['golem-whistle']];
    $state = $state->with(['board' => $board]);

    [$state, $events] = resolveEventRelicForTest($state, 'wanderer_a', 'golem-avarice');

    expect($state->board['1,0']['relics'])->toBe([])
        ->and($state->board['0,0']['relics'])->toBe(['golem-whistle', 'burial-robes', 'last-rites'])
        ->and(collect($events)->pluck('type'))->toContain('relics_moved_to_golem');
});

test('Magic explosion spreads the caster\'s Relics evenly to neighbors and places Monolith Shards there', function () {
    $state = startedGame();
    $board = $state->board;
    // Clear whatever Setup dealt to every starting cell so the counts
    // below only reflect what this effect itself moved/placed.
    foreach ($board as $key => $entry) {
        $board[$key] = [...$entry, 'relics' => []];
    }
    $board['1,0'] = [...$board['1,0'], 'relics' => ['burial-robes', 'last-rites']];
    $state = $state->with(['board' => $board]);

    $shardPoolBefore = $state->infrastructurePool['monolith_shard'];

    [$state, $events] = resolveEventRelicForTest($state, 'wanderer_a', 'magic-explosion');

    expect($state->board['1,0']['relics'])->toBe([]);

    $neighborKeys = ['2,0', '2,1', '1,1', '0,1', '0,0'];
    $landedCount = 0;
    $shardedCount = 0;
    foreach ($neighborKeys as $key) {
        $landedCount += count($state->board[$key]['relics']);
        if (in_array('monolith_shard', $state->board[$key]['infrastructure'], true)) {
            $shardedCount++;
        }
    }

    expect($landedCount)->toBe(2)
        ->and($shardedCount)->toBe(2) // 2 distinct neighbors received a Relic
        ->and($state->infrastructurePool['monolith_shard'])->toBe($shardPoolBefore - 2)
        ->and(collect($events)->pluck('type'))->toContain('infrastructure_placed');
});

test('A rush of companionship heals Wanderers on or adjacent to a shared space by 5', function () {
    $state = startedGame(); // both Wanderers already share the hub cell
    $wanderers = $state->wanderersWith('wanderer_a', ['hp' => 5]);
    $wanderers['wanderer_b']['hp'] = 5;
    $state = $state->with(['wanderers' => $wanderers]);

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'a-rush-of-companionship');

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(10);
});

test('A rush of companionship does not heal a lone Wanderer with no one nearby', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 0], 'hp' => 5])]);
    // [0,0] and [2,0] are each only adjacent to the hub, not to each other.
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [2, 0], 'hp' => 5])]);

    [$state] = resolveEventRelicForTest($state, 'wanderer_a', 'a-rush-of-companionship');

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(5);
});

test('Finding an Event Relic via search resolves it immediately instead of holding it', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'relics' => ['sharp-objects']];
    $state = $state->with(['board' => $board]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'search_location']);

    expect($state->wanderers['wanderer_a']['holding'])->toBe([])
        ->and($state->wanderers['wanderer_a']['equipped'])->toContain('scar_token')
        ->and(collect($events)->pluck('type'))->toContain('location_searched');
});

test('Pray adds a 📖 Glyph exactly 3 spaces from the Golem, then trashes', function () {
    $state = startedGame();
    $board = $state->board;
    $board['3,0'] = ['location_key' => 'cathedral', 'placed_round' => 2, 'relics' => [], 'infrastructure' => []];
    $board['4,0'] = ['location_key' => 'cathedral', 'placed_round' => 3, 'relics' => [], 'infrastructure' => []];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'pray'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'cell' => [4, 0]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'pray']);

    expect($state->golem['glyphs'])->toHaveCount(1)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Pray does nothing at the wrong distance', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'pray'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'pray']);

    expect($state->golem['glyphs'])->toBe([]);
});
