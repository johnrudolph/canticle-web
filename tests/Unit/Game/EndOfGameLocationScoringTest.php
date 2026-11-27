<?php

use App\Game\GameEngine;
use App\Game\GameState;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

function applyEndOfGameScoringForTest(GameState $state): array
{
    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $state);
}

test('Music academy gains 📜 when all Wanderers have a 🎶 Relic equipped', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'music-academy'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'lute']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => [...$state->wanderers['wanderer_b']['equipped'], 'hand_left' => 'pipe']])]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'music-academy')['payload']['amount'])->toBe(1);
});

test('Music academy does not score if one Wanderer has no 🎶 Relic', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'music-academy'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'lute']])]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'music-academy'))->toBeNull();
});

test('Cursed Tomb gains 📜📜📜 when all Wanderers are exhausted', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'cursed-tomb'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 0])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['hp' => 0])]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'cursed-tomb')['payload']['amount'])->toBe(3);
});

test('Haunted Clocktower gains 📜📜 when a former Emperor is exhausted', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'haunted-clocktower'];
    $state = $state->with(['board' => $board, 'formerEmperorRoles' => ['wanderer_a']]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 0])]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'haunted-clocktower')['payload']['amount'])->toBe(2);
});

test('Haunted Clocktower does not score if the former Emperor is not exhausted', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'haunted-clocktower'];
    $state = $state->with(['board' => $board, 'formerEmperorRoles' => ['wanderer_a']]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'haunted-clocktower'))->toBeNull();
});

test('Scriptorium gains 📜📜 when the Monolith Shard pool is empty', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'scriptorium'];
    $state = $state->with(['board' => $board, 'infrastructurePool' => [...$state->infrastructurePool, 'monolith_shard' => 0]]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'scriptorium')['payload']['amount'])->toBe(2);
});

test('Purification pools gains 📜 when all Wanderers have 0 Shame and 0 Burn', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'purification-pools'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['shame' => 0, 'burned_slots' => []])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['shame' => 0, 'burned_slots' => []])]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'purification-pools')['payload']['amount'])->toBe(1);
});

test('Purification pools does not score if a Wanderer has Shame', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'purification-pools'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['shame' => 1])]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'purification-pools'))->toBeNull();
});

test('Prison gains 📜 when the Golem has 3+ Shame', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'prison'];
    $state = $state->with(['board' => $board, 'golem' => $state->golemWith(['shame' => 3])]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'prison')['payload']['amount'])->toBe(1);
});

test('Golem sanctuary gains 📜📜 once 5 Relics are tucked there', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'golem-sanctuary', 'tucked' => ['holy-water', 'balm', 'last-rites', 'divine-healing-oil', 'hands-of-forgiveness']];
    $state = $state->with(['board' => $board]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'golem-sanctuary')['payload']['amount'])->toBe(2);
});

test('Golem sanctuary does not score below the 5-tucked threshold', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'location_key' => 'golem-sanctuary', 'tucked' => ['holy-water']];
    $state = $state->with(['board' => $board]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'golem-sanctuary'))->toBeNull();
});

test('Exile gains 📜📜📜 when all active Wanderers are there', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'exile'];
    $state = $state->with(['board' => $board]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'exile')['payload']['amount'])->toBe(3);
});

test('Exile does not score if a Wanderer is elsewhere', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'exile'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 0]])]);

    [, $events] = applyEndOfGameScoringForTest($state);

    expect(collect($events)->firstWhere('payload.reason', 'exile'))->toBeNull();
});
