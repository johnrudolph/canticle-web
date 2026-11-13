<?php

use App\Game\GameEngine;
use App\Game\GameState;
use App\Game\InvalidMoveException;

// startedGame() is declared in GameEngineTest.php, loaded in the same run.

test('a slot holding a status token cannot be equipped into', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'scar_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped,
        'holding' => ['burial-robes'],
    ])]);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => 'burial-robes', 'slot' => 'body']);
})->throws(InvalidMoveException::class, 'That slot is blocked by a status token.');

test('a status token cannot be dropped or used like a Relic', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'shame_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'drop_relic', 'relic_key' => 'shame_token']);
})->throws(InvalidMoveException::class, 'Status tokens are not dropped this way.');

test('Holy water removes Shame if present, otherwise Scar', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'scar_token', 'mind' => 'holy-water'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'holy-water']);

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('wanderer_scar_removed');
});

test('Hands of forgiveness removes every Shame and Scar token from the chosen Wanderer', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $wanderers = $state->wanderers;
    $wanderers['wanderer_a']['equipped'] = [...$wanderers['wanderer_a']['equipped'], 'hand_left' => 'hands-of-forgiveness'];
    $wanderers['wanderer_b']['equipped'] = [...$wanderers['wanderer_b']['equipped'], 'body' => 'shame_token', 'mind' => 'scar_token'];
    $wanderers['wanderer_b']['cell'] = $wanderers['wanderer_a']['cell'];
    $state = $state->with(['wanderers' => $wanderers]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'hands-of-forgiveness', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_b']['equipped']['body'])->toBeNull()
        ->and($state->wanderers['wanderer_b']['equipped']['mind'])->toBeNull();
});

test('Shame is capped at 3 tokens; further gains are a no-op', function () {
    $state = startedGame();
    $equipped = $state->wanderers['wanderer_a']['equipped'];
    $equipped['body'] = 'shame_token';
    $equipped['mind'] = 'shame_token';
    $equipped['hand_left'] = 'shame_token';
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyStatusToken');
    $reflection->setAccessible(true);
    [$newState, $events] = $reflection->invoke(null, $state, 'wanderer_a', 'shame_token');

    expect($events)->toBe([])
        ->and($newState->wanderers['wanderer_a']['equipped']['hand_right'])->toBeNull();
});

test('a status token only ever occupies an empty slot, never displacing a Relic', function () {
    $state = startedGame();
    $allFull = ['body' => 'r1', 'mind' => 'r2', 'hand_left' => 'r3', 'hand_right' => 'r4', 'bag' => 'r5'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $allFull])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyStatusToken');
    $reflection->setAccessible(true);
    [$newState, $events] = $reflection->invoke(null, $state, 'wanderer_a', 'scar_token');

    expect($events)->toBe([])
        ->and($newState->wanderers['wanderer_a']['equipped'])->toBe($allFull);
});

function resolveGolemCardForTest(GameState $state, string $cardKey): array
{
    $reflection = new ReflectionMethod(GameEngine::class, 'resolveGolemCard');
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $state, $cardKey);
}

test('the Scar Golem card scars the nearest Wanderer', function () {
    $state = startedGame();
    [$newState] = resolveGolemCardForTest($state, 'scar');

    $scarredRoles = array_filter(
        $newState->wanderers,
        fn ($w) => in_array('scar_token', $w['equipped'], true)
    );

    expect($scarredRoles)->toHaveCount(1);
});

test('Public Shaming damages every Wanderer by 2x their own Shame count', function () {
    $state = startedGame();
    $wanderers = $state->wanderers;
    $wanderers['wanderer_a']['equipped'] = [...$wanderers['wanderer_a']['equipped'], 'body' => 'shame_token', 'mind' => 'shame_token'];
    $state = $state->with(['wanderers' => $wanderers]);

    [$newState, $events] = resolveGolemCardForTest($state, 'public-shaming');

    expect($newState->wanderers['wanderer_a']['hp'])->toBe(11) // 15 - 2*2
        ->and($newState->wanderers['wanderer_b']['hp'])->toBe(15) // no Shame, untouched
        ->and(collect($events)->pluck('type'))->toContain('wanderer_damaged');
});

test('Public Lashings only damages its target if the Golem actually reaches them', function () {
    $state = startedGame();
    $wanderers = $state->wanderers;
    $wanderers['wanderer_a']['equipped'] = [...$wanderers['wanderer_a']['equipped'], 'body' => 'shame_token'];
    // Golem starts right on wanderer_a's own cell, so "reaches them" is trivially true.
    $wanderers['wanderer_a']['cell'] = $state->golem['cell'];
    $state = $state->with(['wanderers' => $wanderers]);

    [$newState] = resolveGolemCardForTest($state, 'public-lashings');

    expect($newState->wanderers['wanderer_a']['hp'])->toBe(12); // 15 - 3*1
});
