<?php

use App\Game\GameEngine;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Hands of pity heals self by Shame + Burn token count', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'hands-of-pity'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'shame' => 2, 'burned_slots' => ['hand_right'], 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'hands-of-pity']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(8)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_healed');
});

test('Hands of pity heals an Ally on the same space by their own Shame + Burn count', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'hands-of-pity'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['shame' => 1, 'burned_slots' => [], 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'hands-of-pity', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_b']['hp'])->toBe(6)
        ->and($state->wanderers['wanderer_a']['hp'])->toBe($state->wanderers['wanderer_a']['hp']);
});

test('Degenerate jig grants an extra action and a Shame token only to opted-in instrument holders', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'degenerate-jig'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $bEquipped = [...$state->wanderers['wanderer_b']['equipped'], 'bag' => 'lute'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $bEquipped, 'shame' => 0])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $actionsBeforeA = $state->wanderers['wanderer_a']['actions_remaining'];
    $actionsBeforeB = $state->wanderers['wanderer_b']['actions_remaining'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'degenerate-jig', 'roles' => ['wanderer_a', 'wanderer_b'],
    ]);

    // wanderer_a used an action to play this Relic, then it's excluded
    // from "roles" eligibility anyway since it has no instrument equipped.
    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBeforeA - 1)
        ->and($state->wanderers['wanderer_b']['actions_remaining'])->toBe($actionsBeforeB + 1)
        ->and($state->wanderers['wanderer_b']['shame'])->toBe(1)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_action_granted');
});

test('Degenerate jig skips a listed Wanderer with no instrument equipped', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'degenerate-jig'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['shame' => 0])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $actionsBeforeB = $state->wanderers['wanderer_b']['actions_remaining'];

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'degenerate-jig', 'roles' => ['wanderer_b'],
    ]);

    expect($state->wanderers['wanderer_b']['actions_remaining'])->toBe($actionsBeforeB)
        ->and($state->wanderers['wanderer_b']['shame'])->toBe(0);
});

test('Timeless waltz moves an instrument-equipped Wanderer on a ⏳ space to any chosen space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'sundial'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'timeless-waltz'];
    $bEquipped = [...$state->wanderers['wanderer_b']['equipped'], 'bag' => 'pipe'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $bEquipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic',
        'relic_key' => 'timeless-waltz',
        'destinations' => ['wanderer_b' => [0, 1]],
    ]);

    expect($state->wanderers['wanderer_b']['cell'])->toBe([0, 1])
        ->and(collect($events)->pluck('type'))->toContain('wanderer_moved');
});

test('Timeless waltz skips a Wanderer not standing on a ⏳ space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'timeless-waltz'];
    $bEquipped = [...$state->wanderers['wanderer_b']['equipped'], 'bag' => 'pipe'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $bEquipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic',
        'relic_key' => 'timeless-waltz',
        'destinations' => ['wanderer_b' => [0, 1]],
    ]);

    expect($state->wanderers['wanderer_b']['cell'])->toBe([1, 0]);
});

test('Timeless waltz moves the Golem to any space when it is on a ⏳ space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'sundial'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'timeless-waltz'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic',
        'relic_key' => 'timeless-waltz',
        'destinations' => [],
        'golem_cell' => [0, 1],
    ]);

    expect($state->golem['cell'])->toBe([0, 1])
        ->and(collect($events)->pluck('type'))->toContain('golem_moved');
});
