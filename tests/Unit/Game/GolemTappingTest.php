<?php

use App\Game\GameEngine;
use App\Game\InvalidMoveException;

// startedGame() and resolveGolemCardForTest() are declared in
// GameEngineTest.php / StatusTokensTest.php, loaded in the same run.

test('a tapped card in the revealed row does nothing and is discarded without resolving', function () {
    $state = startedGame();
    $golem = $state->golem;
    array_pop($golem['revealed']);
    $golem['revealed'][] = ['key' => 'storm', 'tapped' => true];
    $state = $state->with(['golem' => $golem]);

    $hpBefore = $state->wanderers['wanderer_a']['hp'];

    $reflection = new ReflectionMethod(GameEngine::class, 'resolveOrSkipTappedCard');
    $reflection->setAccessible(true);
    [$newState, $events] = $reflection->invoke(null, $state, ['key' => 'storm', 'tapped' => true]);

    expect(collect($events)->pluck('type')->all())->toBe(['golem_card_tapped'])
        ->and($newState->wanderers['wanderer_a']['hp'])->toBe($hpBefore); // Storm's -2 never applied
});

test('an untapped card resolves normally', function () {
    $state = startedGame();

    $reflection = new ReflectionMethod(GameEngine::class, 'resolveOrSkipTappedCard');
    $reflection->setAccessible(true);
    [$newState, $events] = $reflection->invoke(null, $state, ['key' => 'storm', 'tapped' => false]);

    expect($newState->wanderers['wanderer_a']['hp'])->toBe(13) // 15 - 2
        ->and(collect($events)->pluck('type'))->toContain('golem_card_resolved');
});

test('tapGolemDrawPile flips exactly N untapped cards from the top, leaving already-tapped ones alone', function () {
    $state = startedGame();
    $golem = $state->golem;
    $golem['draw_pile'] = [
        ['key' => 'a', 'tapped' => true],
        ['key' => 'b', 'tapped' => false],
        ['key' => 'c', 'tapped' => false],
        ['key' => 'd', 'tapped' => false],
    ];
    $state = $state->with(['golem' => $golem]);

    $reflection = new ReflectionMethod(GameEngine::class, 'tapGolemDrawPile');
    $reflection->setAccessible(true);
    [$newState, $events, $tappedCount] = $reflection->invoke(null, $state, 2);

    expect($tappedCount)->toBe(2)
        ->and($newState->golem['draw_pile'])->toBe([
            ['key' => 'a', 'tapped' => true],
            ['key' => 'b', 'tapped' => true],
            ['key' => 'c', 'tapped' => true],
            ['key' => 'd', 'tapped' => false],
        ])
        ->and($events)->toHaveCount(2);
});

test('Whip of Justice taps one card per Golem Shame and trashes itself, only on the Golem\'s space', function () {
    $state = startedGame();
    $golem = $state->golem;
    $golem['shame'] = 2;
    $state = $state->with(['golem' => $golem]);

    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'whip-of-justice'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped,
        'cell' => $state->golem['cell'],
    ])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'whip-of-justice']);

    $tappedCount = count(array_filter($state->golem['draw_pile'], fn ($c) => $c['tapped']));

    expect($tappedCount)->toBe(2)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Whip of Justice is rejected outright off the Golem\'s space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'whip-of-justice'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    // wanderer_a's cell isn't the Golem's cell in this setup (startedGame
    // puts both Wanderers and the Golem on the same hub cell [1,0] by
    // default) — force it elsewhere.
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 1]])]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'whip-of-justice']))
        ->toThrow(InvalidMoveException::class);

    expect($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('whip-of-justice');
});

test('Peacemaker\'s spirit taps the Golem\'s next 2 untapped cards without trashing itself', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'peacemakers-spirit'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'peacemakers-spirit']);

    $tappedCount = count(array_filter($state->golem['draw_pile'], fn ($c) => $c['tapped']));

    expect($tappedCount)->toBe(2)
        ->and($state->wanderers['wanderer_a']['equipped']['mind'])->toBe('peacemakers-spirit');
});

test('Blinding explosion damages everyone on/adjacent to the caster, conditionally taps, and always trashes', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'blinding-explosion'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped]);
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $hpBeforeA = $state->wanderers['wanderer_a']['hp'];
    $hpBeforeB = $state->wanderers['wanderer_b']['hp'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'blinding-explosion']);

    $tappedCount = count(array_filter($state->golem['draw_pile'], fn ($c) => $c['tapped']));

    expect($state->wanderers['wanderer_a']['hp'])->toBe($hpBeforeA - 5)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe($hpBeforeB - 5) // startedGame shares a cell
        ->and($tappedCount)->toBe(4) // Golem shares the caster's cell by default
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Soothing Lullaby requires the Golem\'s space and all 3 instruments equipped in it', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'soothing-lullaby'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped]);
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    // No instruments equipped yet — rejected outright, no trash.
    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'soothing-lullaby']))
        ->toThrow(InvalidMoveException::class);
    expect($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('soothing-lullaby');

    // Equip all 3 instruments across both Wanderers sharing the caster's space.
    $wanderers = $state->wanderers;
    $wanderers['wanderer_a']['equipped']['hand_right'] = 'lute';
    $wanderers['wanderer_a']['equipped']['bag'] = 'pipe';
    $wanderers['wanderer_b']['equipped']['body'] = 'tabor-drum';
    $state = $state->with(['wanderers' => $wanderers]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'soothing-lullaby']);

    $tappedCount = count(array_filter($state->golem['draw_pile'], fn ($c) => $c['tapped']));

    expect($tappedCount)->toBe(3)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});
