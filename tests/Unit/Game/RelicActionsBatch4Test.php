<?php

use App\Game\GameEngine;
use App\Game\InvalidMoveException;

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
        ->and($state->prophecy)->toBe(1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed', 'prophecy_gained');
});

test('peek_location_deck reveals the deck without changing state or costing an action, gated on holding the Relic', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'an-insight-from-the-future'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $actionsBefore = $state->wanderers['wanderer_a']['actions_remaining'];
    $deckBefore = $state->locationDeck;

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'peek_location_deck', 'relic_key' => 'an-insight-from-the-future',
    ]);

    expect($state->locationDeck)->toBe($deckBefore)
        ->and($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBefore)
        ->and($events)->toBe([]);

    expect(fn () => GameEngine::apply($state, 'wanderer_b', [
        'type' => 'peek_location_deck', 'relic_key' => 'an-insight-from-the-future',
    ]))->toThrow(InvalidMoveException::class, 'That Relic is not equipped.');
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
    $prophecyBefore = $state->prophecy;

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'aural-inscription']);

    expect($state->golem['glyphs'])->toHaveCount(1)
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Aural inscription is rejected outright if two Wanderers share a space', function () {
    $state = startedGame(); // both Wanderers share the hub, distance 0 from the Golem too
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'aural-inscription'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'aural-inscription']))
        ->toThrow(InvalidMoveException::class);

    expect($state->golem['glyphs'])->toBe([]);
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
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed')
        ->and($state->prophecy)->toBe(1);
});

test('Divine Chant works on any space that produces 📖, not just literally the Cathedral', function () {
    $state = startedGame();
    $board = $state->board;
    $board['3,0'] = ['location_key' => 'purification-pools', 'placed_round' => 2, 'relics' => [], 'infrastructure' => []]; // also produces 📖
    $state = $state->with(['board' => $board]);
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'lute', 'hand_right' => 'pipe', 'bag' => 'tabor-drum', 'mind' => 'divine-chant',
    ];
    $wanderers = $state->wanderersWith('wanderer_a', ['cell' => [3, 0], 'equipped' => $equipped, 'hp' => 5]);
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-chant']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(15)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Divine Chant is rejected outright on a space that does not produce 📖', function () {
    $state = startedGame();
    $board = $state->board;
    $board['3,0'] = ['location_key' => 'artists-hovel', 'placed_round' => 2, 'relics' => [], 'infrastructure' => []]; // produces 🎨, not 📖
    $state = $state->with(['board' => $board]);
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'lute', 'hand_right' => 'pipe', 'bag' => 'tabor-drum', 'mind' => 'divine-chant',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [3, 0], 'equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-chant']))
        ->toThrow(InvalidMoveException::class);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
});

test('Divine Chant is rejected outright if the Golem is less than 2 spaces away', function () {
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

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-chant']))
        ->toThrow(InvalidMoveException::class);

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
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['shame' => 2])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'magistrates-robes', 'from_role' => 'wanderer_b', 'to_role' => 'golem',
    ]);

    expect($state->golem['shame'])->toBe(2)
        ->and($state->wanderers['wanderer_b']['shame'])->toBe(0);
});

test('Magistrate\'s robes caps the destination at 3 Shame tokens', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'school-of-law'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'magistrates-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['shame' => 2])]);
    $state = $state->with(['golem' => $state->golemWith(['shame' => 2])]);

    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'magistrates-robes', 'from_role' => 'wanderer_b', 'to_role' => 'golem',
    ]);

    // Only 1 of the 2 could move before hitting the cap of 3.
    expect($state->golem['shame'])->toBe(3);
});

test('Funeral dirge halves everyone\'s HP, removes a Glyph, and trashes, once all 3 instruments are each on a 🌑 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'cursed-tomb']; // produces 🌑
    $state = $state->with(['board' => $board]);
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'lute', 'hand_right' => 'pipe', 'mind' => 'funeral-dirge',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);
    $equippedB = [...$state->wanderers['wanderer_b']['equipped'], 'bag' => 'tabor-drum'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equippedB, 'hp' => 7])]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['cruel']])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'funeral-dirge', 'glyph_key' => 'cruel',
    ]);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5) // 10 - 5
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(4) // 7 - 3 (rounded down)
        ->and($state->wanderers['wanderer_a']['equipped']['mind'])->toBeNull()
        ->and($state->golem['glyphs'])->toBe([])
        ->and(collect($events)->pluck('type'))->toContain('glyph_discarded');
});

test('Funeral dirge is rejected outright unless every instrument holder is on their own 🌑 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // produces 🎨, not 🌑
    $state = $state->with(['board' => $board]);
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'lute', 'hand_right' => 'pipe', 'mind' => 'funeral-dirge',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);
    $equippedB = [...$state->wanderers['wanderer_b']['equipped'], 'bag' => 'tabor-drum'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equippedB, 'hp' => 7])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'funeral-dirge']))
        ->toThrow(InvalidMoveException::class);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(7);
});

test('Magistrate\'s robes is rejected outright off a ⚖️ space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // produces 🎨, not ⚖️
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'magistrates-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'magistrates-robes', 'from_role' => 'wanderer_b', 'to_role' => 'golem',
    ]))->toThrow(InvalidMoveException::class);

    expect($state->golem['shame'])->toBe(0);
});
