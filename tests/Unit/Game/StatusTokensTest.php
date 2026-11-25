<?php

use App\Game\GameEngine;
use App\Game\GameState;
use App\Game\InvalidMoveException;

// startedGame() is declared in GameEngineTest.php, loaded in the same run.

test('a slot holding a Burn cannot be equipped into', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'burned_slots' => ['body'],
        'holding' => ['burial-robes'],
    ])]);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => 'burial-robes', 'slot' => 'body']);
})->throws(InvalidMoveException::class, 'That slot is blocked by a Burn.');

test('a Burn token cannot be dropped or used like a Relic', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burn_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'drop_relic', 'relic_key' => 'burn_token']);
})->throws(InvalidMoveException::class, 'Status tokens are not dropped this way.');

test('Holy water removes Shame if present, otherwise Burn', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'holy-water'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'burned_slots' => ['body']])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'holy-water']);

    expect($state->wanderers['wanderer_a']['burned_slots'])->toBe([])
        ->and(collect($events)->pluck('type'))->toContain('wanderer_burn_removed');
});

test('Balm removes a Burn from yourself or an Ally on your space', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equippedA = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'balm'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equippedA])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['burned_slots' => ['body']])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'balm', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_b']['burned_slots'])->toBe([])
        ->and(collect($events)->pluck('type'))->toContain('wanderer_burn_removed');
});

test('Balm defaults to removing the caster\'s own Burn when no target is given', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'balm'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'burned_slots' => ['body']])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'balm']);

    expect($state->wanderers['wanderer_a']['burned_slots'])->toBe([]);
});

test('Hands of forgiveness removes every Shame and Burn token from the chosen Wanderer', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $wanderers = $state->wanderers;
    $wanderers['wanderer_a']['equipped'] = [...$wanderers['wanderer_a']['equipped'], 'hand_left' => 'hands-of-forgiveness'];
    $wanderers['wanderer_b']['burned_slots'] = ['mind'];
    $wanderers['wanderer_b']['shame'] = 2;
    $wanderers['wanderer_b']['cell'] = $wanderers['wanderer_a']['cell'];
    $state = $state->with(['wanderers' => $wanderers]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'hands-of-forgiveness', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_b']['burned_slots'])->toBe([])
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
        'hand_left' => 'hands-of-forgiveness',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'shame' => 2, 'burned_slots' => ['mind']])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'hands-of-forgiveness',
    ]);

    expect($state->wanderers['wanderer_a']['burned_slots'])->toBe([])
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

    expect($state->wanderers['wanderer_a']['burned_slots'])->toBe([])
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

    expect($state->wanderers['wanderer_a']['burned_slots'])->toBe(['mind'])
        ->and($state->wanderers['wanderer_a']['equipped']['mind'])->toBeNull()
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

    expect($state->wanderers['wanderer_a']['burned_slots'])->toBe(['hand_left'])
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and($state->board['1,0']['relics_face_up'])->toContain('burial-robes')
        ->and(collect($events)->pluck('type'))->toContain('relic_dropped');
});

test('a slot already holding a Burn is not offered as a choice again', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['burned_slots' => ['body']])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a');

    // 4 remaining legal slots (every slot except the already-burned
    // 'body') is still 2+, so this queues rather than auto-applying.
    expect($state->pendingStatusTokenChoices)->toHaveCount(1);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'resolve_status_token_choice', 'slot' => 'body']);

    // Choosing the already-burned slot is an invalid choice — a no-op,
    // not a second Burn stacked there.
    expect($state->wanderers['wanderer_a']['burned_slots'])->toBe(['body']);
});

test('an invalid slot choice is a no-op but still clears the pending queue', function () {
    $state = startedGame();

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a');

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'resolve_status_token_choice', 'slot' => 'not-a-real-slot']);

    expect($state->wanderers['wanderer_a']['burned_slots'])->toBe([])
        ->and($state->pendingStatusTokenChoices)->toBe([]);
});

test('a Burn is a no-op once every slot is already burned', function () {
    $state = startedGame();
    $allBurnedSlots = array_keys($state->wanderers['wanderer_a']['equipped']);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['burned_slots' => $allBurnedSlots])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyBurn');
    $reflection->setAccessible(true);
    [$newState, $events] = $reflection->invoke(null, $state, 'wanderer_a');

    expect($events)->toBe([])
        ->and($newState->wanderers['wanderer_a']['burned_slots'])->toBe($allBurnedSlots);
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
        fn ($w) => $w['burned_slots'] !== []
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

test('Inflamed will lets a Burned slot hold a Relic instead of blocking it', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'calloused'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped,
        'burned_slots' => ['body'],
        'holding' => ['burial-robes'],
    ])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => 'burial-robes', 'slot' => 'body']);

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBe('burial-robes')
        ->and($state->wanderers['wanderer_a']['burned_slots'])->toBe(['body']);
});

test('A new Burn on an Inflamed-will holder leaves the Relic already in that slot in place', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'calloused', 'hand_left' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'placeBurn');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 'hand_left');

    expect($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('burial-robes')
        ->and($state->wanderers['wanderer_a']['burned_slots'])->toBe(['hand_left'])
        ->and(collect($events)->pluck('type'))->not->toContain('relic_dropped');
});

test('Dropping Inflamed will also drops any Relic it was propping up in a Burned slot', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'calloused', 'body' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped,
        'burned_slots' => ['body'],
        'cell' => [1, 0],
    ])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'drop_relic', 'relic_key' => 'calloused']);

    expect($state->wanderers['wanderer_a']['equipped']['mind'])->toBeNull()
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and($state->board['1,0']['relics_face_up'])->toContain('calloused', 'burial-robes')
        ->and(collect($events)->pluck('type')->filter(fn ($t) => $t === 'relic_dropped'))->toHaveCount(2);
});

test('Calloused hands reduces incoming damage by 1 per Burn on the mat', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'calloused-hands'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 15, 'burned_slots' => ['body', 'mind']])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 5, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(12); // 15 - (5 - 2)
});

test('Cabalist medallion grants Prophecy at end of game with 5 Burns on the mat', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'cabalist-medallion'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped,
        'burned_slots' => ['body', 'mind', 'hand_left', 'hand_right', 'mind_2'],
    ])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(1)
        ->and(collect($events)->pluck('type'))->toContain('prophecy_gained');
});

test('Torrential rains trashes every equipped 🔥 Relic across all Wanderers', function () {
    $state = startedGame();
    $equippedA = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'cabalist-medallion', 'hand_left' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equippedA])]);
    $equippedB = [...$state->wanderers['wanderer_b']['equipped'], 'hand_left' => 'calloused-hands'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equippedB])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'resolveGolemCard');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'torrential-rains', false);

    expect($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('burial-robes') // not 🔥, untouched
        ->and($state->wanderers['wanderer_b']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Roaring flame grants a Burn token when resolved as an Event', function () {
    $state = startedGame();

    $reflection = new ReflectionMethod(GameEngine::class, 'resolveEventRelic');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 'roaring-flame', []);

    // A fresh mat has every slot empty (2+ legal), so this queues a
    // choice rather than auto-applying — same as Sharp objects.
    expect($state->pendingStatusTokenChoices)->toBe([['role' => 'wanderer_a', 'token' => 'burn_token']])
        ->and(collect($events)->pluck('type'))->toContain('status_token_choice_offered');
});
