<?php

use App\Game\GameEngine;
use App\Game\InvalidMoveException;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run.

test('rest heals 2 Health and costs 1 action', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'rest']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(12)
        ->and($state->wanderers['wanderer_a']['actions_remaining'])->toBe(2);
});

test('rest caps Health at 15', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 14])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'rest']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(15);
});

test('a Wanderer at 0 Health can only rest, not move/search/use a Relic', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 0])]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']))
        ->toThrow(InvalidMoveException::class);
    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'search_location']))
        ->toThrow(InvalidMoveException::class);
    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'burial-robes']))
        ->toThrow(InvalidMoveException::class);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'rest']);
    expect($state->wanderers['wanderer_a']['hp'])->toBe(2);
});

test('a Bound Relic cannot be dropped once equipped', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'drop_relic', 'relic_key' => 'burial-robes']))
        ->toThrow(InvalidMoveException::class, 'Bound Relics cannot be dropped once equipped.');

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBe('burial-robes');
});

test('a Bound Relic can still be dropped while merely held, not yet equipped', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['holding' => ['burial-robes']])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'drop_relic', 'relic_key' => 'burial-robes']);

    expect($state->wanderers['wanderer_a']['holding'])->toBe([])
        ->and($state->board['1,0']['relics_face_up'])->toContain('burial-robes');
});
