<?php

use App\Game\GameEngine;
use App\Game\GameState;
use App\Game\InvalidMoveException;

// startedGame() is declared in GameEngineTest.php, loaded in the same run.

test('a slot holding a Burn token cannot be equipped into', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burn_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped,
        'holding' => ['burial-robes'],
    ])]);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => 'burial-robes', 'slot' => 'body']);
})->throws(InvalidMoveException::class, 'That slot is blocked by a status token.');

test('a Burn token cannot be dropped or used like a Relic', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burn_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'drop_relic', 'relic_key' => 'burn_token']);
})->throws(InvalidMoveException::class, 'Status tokens are not dropped this way.');

test('Holy water removes Shame if present, otherwise Burn', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burn_token', 'mind' => 'holy-water'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'holy-water']);

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('wanderer_burn_removed');
});

test('Balm removes a Burn from yourself or an Ally on your space', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equippedA = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'balm'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equippedA])]);
    $equippedB = [...$state->wanderers['wanderer_b']['equipped'], 'body' => 'burn_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equippedB])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'balm', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_b']['equipped']['body'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('wanderer_burn_removed');
});

test('Balm defaults to removing the caster\'s own Burn when no target is given', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'balm', 'body' => 'burn_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'balm']);

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull();
});

test('Hands of forgiveness removes every Shame and Burn token from the chosen Wanderer', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $wanderers = $state->wanderers;
    $wanderers['wanderer_a']['equipped'] = [...$wanderers['wanderer_a']['equipped'], 'hand_left' => 'hands-of-forgiveness'];
    $wanderers['wanderer_b']['equipped'] = [...$wanderers['wanderer_b']['equipped'], 'mind' => 'burn_token'];
    $wanderers['wanderer_b']['shame'] = 2;
    $wanderers['wanderer_b']['cell'] = $wanderers['wanderer_a']['cell'];
    $state = $state->with(['wanderers' => $wanderers]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'hands-of-forgiveness', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_b']['equipped']['mind'])->toBeNull()
        ->and($state->wanderers['wanderer_b']['shame'])->toBe(0);
});

test('Hands of forgiveness defaults to the caster themselves when no target_role is given', function () {
    // Without an explicit target, this used to no-op entirely — there
    // was no way to target yourself, only an Ally, even though "Choose
    // a Wanderer in your space" explicitly includes the caster.
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'hands-of-forgiveness', 'mind' => 'burn_token',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'shame' => 2])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'hands-of-forgiveness',
    ]);

    expect($state->wanderers['wanderer_a']['equipped']['mind'])->toBeNull()
        ->and($state->wanderers['wanderer_a']['shame'])->toBe(0);
});

test('Shame is capped at 3; further gains are a no-op', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['shame' => 3])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyShame');
    $reflection->setAccessible(true);
    [$newState, $events] = $reflection->invoke(null, $state, 'wanderer_a');

    expect($events)->toBe([])
        ->and($newState->wanderers['wanderer_a']['shame'])->toBe(3);
});

test('Shame never touches an equip slot — it is a plain counter', function () {
    $state = startedGame();

    $reflection = new ReflectionMethod(GameEngine::class, 'applyShame');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a');

    expect($state->wanderers['wanderer_a']['shame'])->toBe(1)
        ->and($state->wanderers['wanderer_a']['equipped'])->toBe(['body' => null, 'mind' => null, 'hand_left' => null, 'hand_right' => null, 'bag' => null, 'mind_2' => null, 'mind_3' => null])
        ->and(collect($events)->pluck('type'))->toContain('wanderer_shamed');
});

test('with 2+ legal slots, gaining a Burn queues a slot choice instead of auto-picking one', function () {
    $state = startedGame();

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a');

    expect($state->wanderers['wanderer_a']['equipped'])->not->toContain('burn_token')
        ->and($state->pendingStatusTokenChoices)->toBe([['role' => 'wanderer_a', 'token' => 'burn_token']])
        ->and(collect($events)->pluck('type'))->toContain('status_token_choice_offered');
});

test('resolve_status_token_choice places the Burn in the exact slot chosen, and blocks other moves until then', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a');

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'rest']))
        ->toThrow(InvalidMoveException::class, 'A Burn token slot choice is waiting before anything else can happen.');

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'resolve_status_token_choice', 'slot' => 'mind']);

    expect($state->wanderers['wanderer_a']['equipped']['mind'])->toBe('burn_token')
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and($state->pendingStatusTokenChoices)->toBe([]);
});

test('choosing a slot that already holds a Relic drops it and burns the slot anyway', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'cell' => [1, 0]])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a');

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'resolve_status_token_choice', 'slot' => 'hand_left']);

    expect($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('burn_token')
        ->and($state->board['1,0']['relics_face_up'])->toContain('burial-robes')
        ->and(collect($events)->pluck('type'))->toContain('relic_dropped');
});

test('a slot already holding a Burn is not offered as a choice again', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burn_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a');

    // 4 remaining legal slots (every slot except the already-burned
    // 'body') is still 2+, so this queues rather than auto-applying.
    expect($state->pendingStatusTokenChoices)->toHaveCount(1);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'resolve_status_token_choice', 'slot' => 'body']);

    // Choosing the already-burned slot is an invalid choice — a no-op,
    // not a second Burn stacked there.
    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBe('burn_token');
});

test('an invalid slot choice is a no-op but still clears the pending queue', function () {
    $state = startedGame();

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a');

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'resolve_status_token_choice', 'slot' => 'not-a-real-slot']);

    expect($state->wanderers['wanderer_a']['equipped'])->not->toContain('burn_token')
        ->and($state->pendingStatusTokenChoices)->toBe([]);
});

test('a Burn is a no-op once every slot is already burned', function () {
    $state = startedGame();
    $allBurned = array_fill_keys(array_keys($state->wanderers['wanderer_a']['equipped']), 'burn_token');
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $allBurned])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$newState, $events] = $reflection->invoke(null, $state, 'wanderer_a');

    expect($events)->toBe([])
        ->and($newState->wanderers['wanderer_a']['equipped'])->toBe($allBurned);
});

function resolveGolemCardForTest(GameState $state, string $cardKey, bool $allowPause = false): array
{
    $reflection = new ReflectionMethod(GameEngine::class, 'resolveGolemCard');
    $reflection->setAccessible(true);

    return $reflection->invoke(null, $state, $cardKey, $allowPause);
}

test('the Scar (now "Char") Golem card Burns the nearest Wanderer', function () {
    $state = startedGame();
    [$newState] = resolveGolemCardForTest($state, 'scar');

    // A fresh mat has every slot empty, so the actual placement is
    // queued for the Wanderer to pick a slot rather than auto-applied.
    expect($newState->pendingStatusTokenChoices)->toHaveCount(1);
    $queuedRole = $newState->pendingStatusTokenChoices[0]['role'];
    [$newState] = GameEngine::apply($newState, $queuedRole, ['type' => 'resolve_status_token_choice', 'slot' => 'body']);

    $burnedRoles = array_filter(
        $newState->wanderers,
        fn ($w) => in_array('burn_token', $w['equipped'], true)
    );

    expect($burnedRoles)->toHaveCount(1);
});

test('Public Shaming damages every Wanderer by 2x their own Shame count', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['shame' => 2])]);

    [$newState, $events] = resolveGolemCardForTest($state, 'public-shaming');

    expect($newState->wanderers['wanderer_a']['hp'])->toBe(11) // 15 - 2*2
        ->and($newState->wanderers['wanderer_b']['hp'])->toBe(15) // no Shame, untouched
        ->and(collect($events)->pluck('type'))->toContain('wanderer_damaged');
});

test('Public Lashings only damages its target if the Golem actually reaches them', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'shame' => 1,
        // Golem starts right on wanderer_a's own cell, so "reaches them" is trivially true.
        'cell' => $state->golem['cell'],
    ])]);

    [$newState] = resolveGolemCardForTest($state, 'public-lashings');

    expect($newState->wanderers['wanderer_a']['hp'])->toBe(12); // 15 - 3*1
});
