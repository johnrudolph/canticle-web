<?php

use App\Game\GameEngine;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('An insight from the future pulls a chosen Location card into the Emperor\'s hand, then trashes', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'an-insight-from-the-future'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $deckBefore = $state->locationDeck;
    $picked = $deckBefore[0];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'an-insight-from-the-future', 'location_key' => $picked,
    ]);

    expect($state->locationHand)->toContain($picked)
        ->and($state->locationDeck)->toHaveCount(count($deckBefore) - 1)
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('An insight from the future does nothing for a card not in the deck', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'an-insight-from-the-future'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'an-insight-from-the-future', 'location_key' => 'not-a-real-location',
    ]);

    expect($state->wanderers['wanderer_a']['equipped']['bag'])->toBe('an-insight-from-the-future')
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('Aural inscription requires every Wanderer adjacent to the Golem on their own distinct space, plus all 3 instruments equipped anywhere', function () {
    $state = startedGame();
    $wanderers = $state->wanderersWith('wanderer_a', [
        'cell' => [0, 0],
        'equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'lute', 'hand_right' => 'pipe'],
    ]);
    $wanderers['wanderer_b']['cell'] = [2, 0];
    $wanderers['wanderer_b']['equipped']['body'] = 'tabor-drum';
    $wanderers['wanderer_a']['equipped']['bag'] = 'aural-inscription';
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'aural-inscription']);

    expect($state->golem['glyphs'])->toHaveCount(1)
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Aural inscription does nothing if two Wanderers share a space', function () {
    $state = startedGame(); // both Wanderers share the hub, distance 0 from the Golem too
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'aural-inscription'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'aural-inscription']);

    expect($state->golem['glyphs'])->toBe([])
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('Divine Chant requires the Cathedral, the Golem 2+ away, and all 3 instruments in the caster\'s space, then heals everyone to full', function () {
    $state = startedGame();
    $board = $state->board;
    $board['3,0'] = ['location_key' => 'cathedral', 'placed_round' => 2, 'relics' => [], 'infrastructure' => []];
    $state = $state->with(['board' => $board]);
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'lute', 'hand_right' => 'pipe', 'bag' => 'tabor-drum',
    ];
    $wanderers = $state->wanderersWith('wanderer_a', ['cell' => [3, 0], 'equipped' => $equipped, 'hp' => 5]);
    $wanderers['wanderer_b']['hp'] = 5;
    $state = $state->with(['wanderers' => $wanderers]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'divine-chant'],
    ])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-chant']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(15)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(15)
        ->and($state->wanderers['wanderer_a']['equipped']['mind'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Divine Chant does nothing if the Golem is less than 2 spaces away', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'cathedral'];
    $state = $state->with(['board' => $board]);
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'lute', 'hand_right' => 'pipe', 'bag' => 'tabor-drum', 'mind' => 'divine-chant',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-chant']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
});

test('Magistrate\'s robes moves Shame from a Wanderer to the Golem while on a ⚖️ space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'school-of-law']; // produces ⚖️
    $state = $state->with(['board' => $board]);
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'magistrates-robes',
    ];
    $bEquipped = [...$state->wanderers['wanderer_b']['equipped'], 'body' => 'shame_token', 'mind' => 'shame_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $bEquipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'magistrates-robes', 'from_role' => 'wanderer_b', 'to_role' => 'golem',
    ]);

    expect($state->golem['shame'])->toBe(2)
        ->and($state->wanderers['wanderer_b']['equipped'])->not->toContain('shame_token');
});

test('Magistrate\'s robes caps the destination at 3 Shame tokens', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'school-of-law'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'magistrates-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $bEquipped = [...$state->wanderers['wanderer_b']['equipped'], 'body' => 'shame_token', 'mind' => 'shame_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $bEquipped])]);
    $state = $state->with(['golem' => $state->golemWith(['shame' => 2])]);

    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'magistrates-robes', 'from_role' => 'wanderer_b', 'to_role' => 'golem',
    ]);

    // Only 1 of the 2 could move before hitting the cap of 3.
    expect($state->golem['shame'])->toBe(3);
});

test('Magistrate\'s robes does nothing off a ⚖️ space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // produces 🎨, not ⚖️
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'magistrates-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'magistrates-robes', 'from_role' => 'wanderer_b', 'to_role' => 'golem',
    ]);

    expect($state->golem['shame'])->toBe(0);
});
