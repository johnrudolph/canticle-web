<?php

use App\Game\Board;
use App\Game\Catalog\LocationCatalog;
use App\Game\GameEngine;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Rod discards an active Golem Glyph and trashes itself', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['cruel']])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'rod'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'rod', 'glyph_key' => 'cruel',
    ]);

    expect($state->golem['glyphs'])->toBe([])
        ->and($state->glyphDiscardPile)->toContain('cruel')
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('glyph_discarded');
});

test('Shear trashes chosen prepared Golem cards permanently (not recycled to discard)', function () {
    $state = startedGame();
    $revealed = $state->golem['revealed'];
    $revealed[0] = ['key' => 'charge', 'tapped' => false];
    $revealed[1] = ['key' => 'storm', 'tapped' => false];
    // Empty draw/discard piles so refillGolemRevealed (which runs after
    // the trash, same as Skip Golem card) can't top the row back up
    // with another copy of 'charge'/'storm' from elsewhere in the
    // deck — keeps the "not toContain" assertion deterministic.
    $state = $state->with(['golem' => $state->golemWith(['revealed' => $revealed, 'draw_pile' => [], 'discard_pile' => [], 'cell' => [1, 0]])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'shear'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [1, 0], 'equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'shear', 'card_keys' => ['charge', 'storm'],
    ]);

    expect(array_column($state->golem['revealed'], 'key'))->not->toContain('charge')
        ->and(array_column($state->golem['revealed'], 'key'))->not->toContain('storm')
        ->and($state->golem['discard_pile'])->toBe([]) // not recycled
        ->and(collect($events)->pluck('type'))->toContain('golem_card_trashed');
});

test('Shear requires sharing the Golem\'s space', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['cell' => [1, 0]])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'shear'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 1], 'equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'shear', 'card_keys' => []]))
        ->toThrow(App\Game\InvalidMoveException::class);
});

test('Golem influence discards an active Glyph and replaces it with a random Glyph of the same type', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['cruel']])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'golem-influence'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'golem-influence', 'glyph_key' => 'cruel',
    ]);

    // Not asserting the replacement differs by key from the discarded
    // Glyph — the deck can legitimately contain more than one physical
    // copy of the same key, so redrawing it isn't a bug.
    expect($state->golem['glyphs'])->toHaveCount(1)
        ->and($state->glyphDiscardPile)->toContain('cruel')
        ->and(collect($events)->pluck('type'))->toContain('glyph_discarded')
        ->and(collect($events)->pluck('type'))->toContain('glyph_added');
});

test('Solidarity badge lets an ally fire one of their own Relics off-turn, without costing the ally an action', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['cruel']])]);
    $equippedA = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'solidarity-badge'];
    $equippedB = [...$state->wanderers['wanderer_b']['equipped'], 'hand_left' => 'rod'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equippedA])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equippedB])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $bActionsBefore = $state->wanderers['wanderer_b']['actions_remaining'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'solidarity-badge',
        'ally_role' => 'wanderer_b', 'ally_relic_key' => 'rod',
        'glyph_key' => 'cruel',
    ]);

    expect($state->golem['glyphs'])->toBe([])
        ->and($state->wanderers['wanderer_b']['equipped']['hand_left'])->toBeNull() // Rod trashed on the ally
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull() // badge trashed on the actor
        ->and($state->wanderers['wanderer_b']['actions_remaining'])->toBe($bActionsBefore)
        ->and(collect($events)->pluck('type'))->toContain('solidarity_badge_used');
});

test('Darkness into light heals 2, or 7 on a 🌑-producing space', function () {
    $state = startedGame();
    $darkCellKey = array_values(array_filter(array_keys($state->board), fn (string $k) => $k !== Board::key([1, 0])))[0];
    $board = $state->board;
    $board[$darkCellKey] = [...$board[$darkCellKey], 'location_key' => 'cursed-tomb'];
    $state = $state->with(['board' => $board]);
    $darkCell = Board::fromKey($darkCellKey);
    expect(LocationCatalog::find('cursed-tomb')->producesEmoji)->toBe('🌑');

    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 1, 'cell' => $darkCell])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['hp' => 1])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'darkness-into-light'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'darkness-into-light']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(8) // 1 + 7, on the 🌑 space
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(3); // 1 + 2, elsewhere
});

test('Harbinger of death deals 15 to every Wanderer and gains 2 prophecy', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 15])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['hp' => 15])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'harbinger-of-death'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $prophecyBefore = $state->prophecy;

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'harbinger-of-death']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(0)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(0)
        ->and($state->prophecy)->toBe($prophecyBefore + 2)
        ->and(collect($events)->pluck('type'))->not->toContain('wanderer_hp_set');
});

test('Golem counter taps one of the 3 revealed Golem cards', function () {
    $state = startedGame();
    $revealed = $state->golem['revealed'];
    $revealed[0] = ['key' => 'charge', 'tapped' => false];
    $state = $state->with(['golem' => $state->golemWith(['revealed' => $revealed, 'cell' => [1, 0]])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'golem-counter'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [1, 0], 'equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'golem-counter', 'card_key' => 'charge',
    ]);

    expect($state->golem['revealed'][0]['tapped'])->toBeTrue()
        ->and(collect($events)->pluck('type'))->toContain('golem_card_flipped_tapped');
});

test('Enraged untaps everything the instant it becomes active, and blocks any further tapping', function () {
    $state = startedGame();
    $drawPile = $state->golem['draw_pile'];
    $drawPile[0]['tapped'] = true;
    $revealed = $state->golem['revealed'];
    $revealed[0] = ['key' => 'charge', 'tapped' => true];
    $state = $state->with(['golem' => $state->golemWith(['draw_pile' => $drawPile, 'revealed' => $revealed, 'cell' => [1, 0]])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'addGlyphOfType');
    $reflection->setAccessible(true);
    $glyphType = App\Game\Catalog\GlyphCatalog::find('enraged')->type;
    // Force Enraged specifically to the front of the deck so addGlyphOfType finds it deterministically.
    $deck = array_values(array_diff($state->glyphDeck, ['enraged']));
    array_unshift($deck, 'enraged');
    $state = $state->with(['glyphDeck' => $deck]);
    [$state, $events] = $reflection->invoke(null, $state, $glyphType);

    expect($state->golem['glyphs'])->toContain('enraged')
        ->and($state->golem['draw_pile'][0]['tapped'])->toBeFalse()
        ->and($state->golem['revealed'][0]['tapped'])->toBeFalse()
        ->and(collect($events)->pluck('type'))->toContain('golem_cards_untapped');

    // Now confirm the gate: Whip of Justice should tap nothing further.
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'whip-of-justice'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [1, 0], 'equipped' => $equipped])]);
    $state = $state->with(['golem' => $state->golemWith(['shame' => 2])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'whip-of-justice']);

    expect(array_filter(array_column($state->golem['draw_pile'], 'tapped')))->toBe([]);
});

test('The ties that bind heals everyone on the space when the equipped Wanderer starts their turn with an ally present', function () {
    $state = startedGame();
    // Neutralize the hub's own randomly-dealt Location text (e.g.
    // Medicinal herb garden's own end-of-turn heal) so only Ties that
    // bind's heal is in play.
    $board = $state->board;
    $board[Board::key([1, 0])] = [...$board[Board::key([1, 0])], 'location_key' => 'pen'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'the-ties-that-bind'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5, 'equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['hp' => 5])]); // same hub cell by default
    // The heal fires the instant wanderer_a's turn actually starts —
    // here, that's end_emperor_phase (the first Wanderer of the
    // round), not any later end_wanderer_turn call.
    [$state, $events] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(8)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(8)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_healed');
});

test('Rage against time deals damage to the Golem\'s space equal to current prophecy', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['cell' => [1, 0]]), 'prophecy' => 4]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 15, 'cell' => [1, 0]])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'resolveGolemCard');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'rage-against-time', false);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(11);
});

test('Whirlwind redistributes all infrastructure tokens with no duplicate type per space', function () {
    $state = startedGame();
    $board = $state->board;
    $cellKeys = array_keys($board);
    $board[$cellKeys[0]]['infrastructure'] = ['monolith_shard'];
    $board[$cellKeys[1]]['infrastructure'] = ['fruit_tree'];
    $board[$cellKeys[2]]['infrastructure'] = ['walls'];
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemWhirlwind');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state);

    $allTokens = [];
    foreach ($state->board as $entry) {
        expect(count($entry['infrastructure']))->toBe(count(array_unique($entry['infrastructure'])));
        $allTokens = [...$allTokens, ...$entry['infrastructure']];
    }

    sort($allTokens);
    expect($allTokens)->toBe(['fruit_tree', 'monolith_shard', 'walls']);
});

test('Garbage barricade reduces Golem attack damage by 1 per 2 Relics tucked there', function () {
    $state = startedGame();
    $board = $state->board;
    $barricadeKey = array_keys($board)[0];
    $board[$barricadeKey] = [...$board[$barricadeKey], 'location_key' => 'garbage-barricade', 'tucked' => ['relic-one', 'relic-two', 'relic-three']];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 15, 'cell' => Board::fromKey($barricadeKey)])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 5, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(11); // 15 - (5 - 1) = 11
});

test('Garbage barricade awards 📜📜📜 at end of game only if no Relics remain face-up or face-down on any Location', function () {
    $state = startedGame();
    $board = $state->board;
    $barricadeKey = array_keys($board)[0];
    $board[$barricadeKey] = [...$board[$barricadeKey], 'location_key' => 'garbage-barricade'];
    foreach ($board as $key => $entry) {
        $board[$key] = [...$entry, 'relics' => [], 'relics_face_up' => [], 'tucked' => []];
    }
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    $prophecyBefore = $state->prophecy;
    [$clearState] = $reflection->invoke(null, $state);
    expect($clearState->prophecy)->toBe($prophecyBefore + 3);

    $board[array_keys($board)[1]]['relics_face_up'] = ['some-relic'];
    $dirtyState = $state->with(['board' => $board]);
    [$dirtyState] = $reflection->invoke(null, $dirtyState);
    expect($dirtyState->prophecy)->toBe($prophecyBefore);
});

test('Fragile Chapel is destroyed and evacuates its Wanderers when the Golem is on or adjacent', function () {
    $state = startedGame();
    $chapelKey = array_values(array_filter(array_keys($state->board), fn (string $k) => $k !== Board::key([1, 0])))[0];
    $board = $state->board;
    $board[$chapelKey] = [...$board[$chapelKey], 'location_key' => 'fragile-chapel'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10, 'cell' => Board::fromKey($chapelKey)])]);
    $state = $state->with(['golem' => $state->golemWith(['cell' => Board::fromKey($chapelKey)])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'checkFragileChapelDestruction');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->board[$chapelKey]['location_key'])->toBe('')
        ->and($state->wanderers['wanderer_a']['hp'])->toBe(0)
        ->and($state->wanderers['wanderer_a']['cell'])->not->toBe(Board::fromKey($chapelKey))
        ->and(collect($events)->pluck('type'))->toContain('location_destroyed')
        ->and(collect($events)->pluck('type'))->toContain('wanderer_hp_set');
});

test('Fragile Chapel does not trigger while the Golem is neither on nor adjacent to it', function () {
    $state = startedGame();
    // A synthetic, far-flung cell — every one of the 6 real starting
    // Locations is adjacent to the hub by construction (see
    // startedGame()'s own doc comment), so none of them would do here.
    $chapelKey = Board::key([50, 50]);
    $board = $state->board;
    $board[$chapelKey] = ['location_key' => 'fragile-chapel', 'placed_round' => 1, 'relics' => [], 'relics_face_up' => [], 'infrastructure' => [], 'tucked' => []];
    $state = $state->with(['board' => $board, 'golem' => $state->golemWith(['cell' => [1, 0]])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'checkFragileChapelDestruction');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->board[$chapelKey]['location_key'])->toBe('fragile-chapel')
        ->and($events)->toBe([]);
});

test('Fragile Chapel awards 📜📜 at end of game only if it was never destroyed', function () {
    $state = startedGame();
    $chapelKey = array_keys($state->board)[0];
    $board = $state->board;
    $board[$chapelKey] = [...$board[$chapelKey], 'location_key' => 'fragile-chapel'];
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    $prophecyBefore = $state->prophecy;
    [$stillHereState] = $reflection->invoke(null, $state);
    expect($stillHereState->prophecy)->toBe($prophecyBefore + 2);

    $board[$chapelKey]['location_key'] = '';
    $destroyedState = $state->with(['board' => $board]);
    [$destroyedState] = $reflection->invoke(null, $destroyedState);
    expect($destroyedState->prophecy)->toBe($prophecyBefore);
});

test('Shroud of time trashes every equipped Relic (including itself) and replaces each from its branch deck', function () {
    $state = startedGame();
    $burialRobesBranch = App\Game\Catalog\RelicCatalog::find('burial-robes')->symbol;
    $deckBefore = count($state->relicDecks[$burialRobesBranch] ?? []);
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'body' => 'shroud-of-time', 'hand_left' => 'burial-robes',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'shroud-of-time']);

    expect($deckBefore)->toBeGreaterThan(0);
    expect($state->trashedRelics)->toContain('shroud-of-time')
        ->and($state->trashedRelics)->toContain('burial-robes')
        ->and(collect($events)->pluck('type')->filter(fn ($t) => $t === 'relic_trashed'))->toHaveCount(2)
        ->and(count($state->relicDecks[$burialRobesBranch] ?? []))->toBe($deckBefore - 1);
});

test('Shroud of time leaves a slot empty if its branch deck is exhausted', function () {
    $state = startedGame();
    $burialRobesBranch = App\Game\Catalog\RelicCatalog::find('burial-robes')->symbol;
    $relicDecks = $state->relicDecks;
    $relicDecks[$burialRobesBranch] = [];
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'burial-robes',
    ];
    $state = $state->with(['relicDecks' => $relicDecks, 'wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'relicShroudOfTime');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a');

    expect($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and($state->trashedRelics)->toContain('burial-robes');
});
