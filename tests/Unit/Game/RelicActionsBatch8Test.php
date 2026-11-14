<?php

use App\Game\GameEngine;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Re-creation recovers a random trashed Relic into holding, then trashes itself', function () {
    $state = startedGame();
    $state = $state->with(['trashedRelics' => ['burial-robes']]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 're-creation'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 're-creation']);

    // Re-creation itself also gets trashed by the normal post-dispatch
    // flow, which is also what feeds this very pool — so it lands back
    // in trashedRelics too, not an empty pool.
    expect($state->wanderers['wanderer_a']['holding'])->toContain('burial-robes')
        ->and($state->trashedRelics)->toBe(['re-creation'])
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Re-creation does nothing if no Relics have ever been trashed', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 're-creation'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 're-creation']);

    expect($state->wanderers['wanderer_a']['equipped']['bag'])->toBe('re-creation')
        ->and($state->wanderers['wanderer_a']['holding'])->toBe([]);
});

test('Trashing a Relic (not dropping it) records it in the global trashedRelics pool', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    // Burial robes is Bound, so it can't be dropped — use Sacrifice of
    // Possessions on it via reflection to trigger a real Golem-driven
    // trash and confirm it lands in the pool.
    $reflection = new ReflectionMethod(GameEngine::class, 'trashFirstEquippedRelic');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a');

    expect($state->trashedRelics)->toBe(['burial-robes']);
});

test('Cabalist brew loses 2 Health, trashes itself, and triples the chosen Relic\'s heal', function () {
    $state = startedGame();
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'bag' => 'cabalist-brew', 'body' => 'divine-healing-oil',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'cabalist-brew', 'other_relic_key' => 'divine-healing-oil',
    ]);

    // -2 from Cabalist brew itself, then divine-healing-oil's normal +3
    // tripled to +9 (its own 1x already applied, plus 2x more) => net
    // 10 - 2 + 9 = 17, capped at 15.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(15)
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBe('divine-healing-oil') // no trash clause of its own
        ->and(collect($events)->pluck('type'))->toContain('wanderer_damaged')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Cabalist brew still costs the Health and trashes itself even with no valid Relic to triple', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'cabalist-brew'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'cabalist-brew']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(8)
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Defanging tool tucks a card from the Golem\'s draw pile and makes it play 3, completing after the 3rd tuck', function () {
    $state = startedGame();
    $golem = $state->golem;
    // Pin both the revealed row and the draw pile it refills from to a
    // non-damaging, non-Double-Down/Time-Paradox filler card — 3 uses
    // means 9 real plays, and a real shuffled deck across that many
    // plays can both deal lethal damage (blocking the next use_relic
    // call) and draw extra plays unpredictably.
    $golem['revealed'] = array_fill(0, 3, ['key' => 'penitence', 'tapped' => false]);
    $golem['draw_pile'] = array_fill(0, 20, ['key' => 'penitence', 'tapped' => false]);
    $state = $state->with(['golem' => $golem]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'defanging-tool'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'defanging-tool']);
    expect($state->wanderers['wanderer_a']['tucked_cards']['defanging-tool'] ?? [])->toHaveCount(1)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('defanging-tool');

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'defanging-tool']);
    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'defanging-tool']);

    expect($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed')
        ->and(collect($events)->pluck('type'))->toContain('card_tucked');
});

test('Defanging tool does nothing off the Golem\'s space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'defanging-tool'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'cell' => [0, 0]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'defanging-tool']);

    expect($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('defanging-tool')
        ->and(collect($events)->pluck('type'))->not->toContain('card_tucked');
});

test('Dropping Defanging tool loses its tucked progress entirely', function () {
    $state = startedGame();
    $wanderer = $state->wanderers['wanderer_a'];
    $wanderer['equipped']['hand_left'] = 'defanging-tool';
    $wanderer['tucked_cards'] = ['defanging-tool' => ['storm']];
    $state = $state->with(['wanderers' => [...$state->wanderers, 'wanderer_a' => $wanderer]]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'drop_relic', 'relic_key' => 'defanging-tool']);

    expect($state->wanderers['wanderer_a']['tucked_cards'])->toBe([])
        ->and(collect($events)->pluck('type'))->toContain('tucked_progress_lost');
});

test('Golem log looks at the top 3, tucks the chosen one, and moves all 3 to discard on completion', function () {
    $state = startedGame();
    $golem = $state->golem;
    $golem['draw_pile'] = [
        ['key' => 'storm', 'tapped' => false],
        ['key' => 'spew', 'tapped' => false],
        ['key' => 'scar', 'tapped' => false],
        ['key' => 'dissent', 'tapped' => false],
    ];
    $state = $state->with(['golem' => $golem]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'golem-log'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-log', 'card_key' => 'spew']);

    expect($state->wanderers['wanderer_a']['tucked_cards']['golem-log'])->toBe(['spew'])
        ->and($state->golem['draw_pile'])->toHaveCount(3) // "spew" removed, 1 card shifted up from beyond the top 3
        ->and(collect($events)->pluck('type'))->toContain('golem_draw_pile_revealed');

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-log', 'card_key' => 'storm']);
    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-log', 'card_key' => 'scar']);

    expect($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and($state->golem['discard_pile'])->toContain('spew', 'storm', 'scar')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Golem log does nothing for a card not actually in the top 3', function () {
    $state = startedGame();
    $golem = $state->golem;
    $golem['draw_pile'] = [
        ['key' => 'storm', 'tapped' => false],
        ['key' => 'spew', 'tapped' => false],
        ['key' => 'scar', 'tapped' => false],
    ];
    $state = $state->with(['golem' => $golem]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'golem-log'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-log', 'card_key' => 'dissent']);

    expect($state->wanderers['wanderer_a']['tucked_cards']['golem-log'] ?? [])->toBe([]);
});

test('Dropping Golem log returns its tucked cards to the Golem\'s discard pile', function () {
    $state = startedGame();
    $wanderer = $state->wanderers['wanderer_a'];
    $wanderer['equipped']['bag'] = 'golem-log';
    $wanderer['tucked_cards'] = ['golem-log' => ['storm', 'spew']];
    $state = $state->with(['wanderers' => [...$state->wanderers, 'wanderer_a' => $wanderer]]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'drop_relic', 'relic_key' => 'golem-log']);

    expect($state->wanderers['wanderer_a']['tucked_cards'])->toBe([])
        ->and($state->golem['discard_pile'])->toContain('storm', 'spew')
        ->and(collect($events)->pluck('type'))->toContain('golem_log_progress_lost');
});

test('Merchant\'s gloves exchanges a Relic between any two Wanderers while on a 🪙 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'bazaar']; // produces 🪙
    $state = $state->with(['board' => $board]);
    $wanderers = $state->wanderersWith('wanderer_a', [
        'equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'merchants-gloves'],
        'cell' => [1, 0],
    ]);
    $wanderers['wanderer_b']['equipped']['hand_left'] = 'last-rites';
    $wanderers['wanderer_b']['cell'] = [0, 0]; // elsewhere on the board entirely
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'merchants-gloves',
        'exchanges' => [['from_role' => 'wanderer_b', 'to_role' => 'wanderer_a', 'relic_key' => 'last-rites']],
    ]);

    expect($state->wanderers['wanderer_b']['equipped']['hand_left'])->toBeNull()
        ->and($state->wanderers['wanderer_a']['holding'])->toContain('last-rites')
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBe('merchants-gloves') // repeatable, no trash
        ->and(collect($events)->pluck('type'))->toContain('relic_exchanged');
});

test('Merchant\'s gloves does nothing off a 🪙 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // produces 🎨, not 🪙
    $state = $state->with(['board' => $board]);
    $wanderers = $state->wanderersWith('wanderer_a', [
        'equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'merchants-gloves'],
    ]);
    $wanderers['wanderer_b']['equipped']['hand_left'] = 'last-rites';
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'merchants-gloves',
        'exchanges' => [['from_role' => 'wanderer_b', 'to_role' => 'wanderer_a', 'relic_key' => 'last-rites']],
    ]);

    expect($state->wanderers['wanderer_b']['equipped']['hand_left'])->toBe('last-rites');
});
