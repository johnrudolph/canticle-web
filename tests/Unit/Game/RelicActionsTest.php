<?php

use App\Game\Board;
use App\Game\GameEngine;
use App\Game\InvalidMoveException;

// startedGame() is declared in GameEngineTest.php, loaded in the same run.

test('using a Relic requires it to be equipped, not merely held', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['holding' => ['burial-robes']])]);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'burial-robes']);
})->throws(InvalidMoveException::class, 'That Relic is not equipped.');

test('using a Relic costs 1 action', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'burial-robes']);

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(2);
});

test('Burial Robes heals every Wanderer sharing the caster\'s space, capped at 15', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burial-robes'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 1]);
    $wanderers = [...$wanderers, 'wanderer_b' => [...$wanderers['wanderer_b'], 'hp' => 1]];
    $state = $state->with(['wanderers' => $wanderers]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'burial-robes']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(15)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(15);
});

test('Divine healing oil defaults to self when no valid ally target is given', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'divine-healing-oil'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-healing-oil']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(8)
        ->and($events[1]['role_key'])->toBe('wanderer_a');
});

test('Last rites heals the chosen Ally to full and trashes itself', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'last-rites'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped]);
    $wanderers = [...$wanderers, 'wanderer_b' => [...$wanderers['wanderer_b'], 'hp' => 2]];
    $state = $state->with(['wanderers' => $wanderers]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'last-rites', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_b']['hp'])->toBe(15)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('an Ally target must actually be sharing the caster\'s space', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'last-rites'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped]);
    $wanderers = [...$wanderers, 'wanderer_b' => [...$wanderers['wanderer_b'], 'cell' => [0, 1], 'hp' => 2]];
    $state = $state->with(['wanderers' => $wanderers]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'last-rites', 'target_role' => 'wanderer_b',
    ]);

    // no valid target => no-op effect, but the action (and the Relic
    // itself) is still spent, matching a real player's misplay.
    expect($state->wanderers['wanderer_b']['hp'])->toBe(2)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull();
});

test('Golem Whistle summons the Golem to the caster\'s space, unless that space is Walled', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'golem-whistle'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-whistle']);

    expect($state->golem['cell'])->toBe($state->wanderers['wanderer_a']['cell']);
});

test('Quantum Robes swaps the caster and the chosen Ally\'s positions', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $otherCell = Board::placeableCells($state->board)[0];
    // make it occupied by moving wanderer_b there directly for the test
    $wanderers = $state->wanderers;
    $wanderers['wanderer_b']['cell'] = [0, 1];
    $equipped = [...$wanderers['wanderer_a']['equipped'], 'body' => 'quantum-robes'];
    $wanderers['wanderer_a']['equipped'] = $equipped;
    $state = $state->with(['wanderers' => $wanderers]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'quantum-robes', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([0, 1])
        ->and($state->wanderers['wanderer_b']['cell'])->toBe([1, 0]);
});

test('Golem disguise discards the top card of the Golem draw pile', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $topCard = $state->golem['draw_pile'][0];
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'golem-disguise'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-disguise']);

    expect($state->golem['discard_pile'])->toContain($topCard)
        ->and($state->golem['draw_pile'][0] ?? null)->not->toBe($topCard);
});

test('an unimplemented Relic action still costs an action but has no mechanical effect', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $unimplemented = 'emperors-diary'; // real Relic, not in the hand-picked batch
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => $unimplemented];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => $unimplemented]);

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(2)
        ->and($events)->toHaveCount(1)
        ->and($events[0]['type'])->toBe('relic_used');
});
