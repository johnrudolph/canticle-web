<?php

use App\Game\Catalog\GlyphCatalog;
use App\Game\GameEngine;
use App\Game\InvalidMoveException;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Dark sacrifice reduces incoming damage by 1 per equipped Dark Relic', function () {
    $state = startedGame();
    $equipped = [
        'body' => 'dark-shackles', 'mind' => null,
        'hand_left' => 'dark-sacrifice', 'hand_right' => null, 'bag' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 5);

    // 2 Dark Relics equipped (dark-shackles + dark-sacrifice itself counts
    // too, it's an equipped "Dark ..." Relic the same as any other) => -2.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(12);

    $damaged = collect($events)->firstWhere('type', 'wanderer_damaged');
    expect($damaged['payload']['amount'])->toBe(3) // 5 - 2, the ACTUAL loss
        ->and($damaged['payload']['raw_amount'])->toBe(5)
        ->and($damaged['payload']['modifiers'])->toBe(['Dark sacrifice (-2)']);
});

test('Dark sacrifice\'s reduction requires Dark sacrifice itself equipped, not just any other Dark Relic', function () {
    // The ability belongs to the Dark sacrifice card specifically —
    // merely having a DIFFERENT Dark Relic equipped (here, Dark shackles
    // alone, no Dark sacrifice) grants no reduction. Same bug shape as
    // Emperor's Knowledge applying its bonus without the card itself
    // equipped.
    $state = startedGame();
    $equipped = [
        'body' => 'dark-shackles', 'mind' => null,
        'hand_left' => null, 'hand_right' => null, 'bag' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 5);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10); // full 5, no reduction

    $damaged = collect($events)->firstWhere('type', 'wanderer_damaged');
    expect($damaged['payload']['amount'])->toBe(5)
        ->and($damaged['payload'])->not->toHaveKey('modifiers');
});

test('Cabalist wraps saves its holder from exhaustion, damages nearby Allies, and taps the Golem', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'cabalist-wraps'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 3]);
    $wanderers['wanderer_b']['hp'] = 10;
    $state = $state->with(['wanderers' => $wanderers]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 10);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(1)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(5) // Ally sharing the hub cell, -5
        ->and(collect($events)->pluck('type'))->toContain('cabalist_wraps_triggered');

    // Cabalist wraps caps the real loss at hpBefore-1 (3 -> 1, a loss of
    // 2), not the raw 10 damage the card dealt — the logged event must
    // say what actually happened, not the attack's raw number.
    $damaged = collect($events)->firstWhere(
        fn ($e) => $e['type'] === 'wanderer_damaged' && $e['role_key'] === 'wanderer_a',
    );
    expect($damaged['payload']['amount'])->toBe(2);
});

test('Cabalist wraps does not trigger if the Wanderer survives above 0', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'cabalist-wraps'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 5);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5)
        ->and(collect($events)->pluck('type'))->not->toContain('cabalist_wraps_triggered');
});

test('Dark limitations caps Health at 7 for every heal', function () {
    // rules.md 2026-11-24 revision: raised from 5 to 7.
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'dark-limitations', 'body' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 1])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'burial-robes']); // normally heals to 15

    expect($state->wanderers['wanderer_a']['hp'])->toBe(7);
});

test('Rejuvenation gown heals 2 when entering a Monolith Shard space', function () {
    $state = startedGame();
    $board = $state->board;
    // Pin the destination's Location explicitly — a real random board
    // could otherwise deal Catacombs there, which blocks entry outright.
    $board['2,0'] = [...$board['2,0'], 'location_key' => 'artists-hovel', 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'rejuvenation-gown'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'NE']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(12);
});

test('Union Garb grants +1 action if the turn starts on a shared space', function () {
    $state = startedGame(); // both Wanderers already share the hub
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'union-garb'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(4); // 3 + 1
});

test('Builder\'s gloves raises the Infrastructure cap to 3 while a holder is on a ⚙️ space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'builders-site']; // produces ⚙️
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'builders-gloves'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    foreach (['monolith_shard', 'road', 'fruit_tree'] as $type) {
        [$state] = GameEngine::apply($state, 'emperor', [
            'type' => 'place_infrastructure', 'infrastructure_type' => $type, 'cell' => [0, 1],
        ]);
    }

    expect($state->infrastructurePlacedThisTurn)->toBe(3);
});

test('Dark shackles pulls its holder along when the Golem moves', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'dark-shackles'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemMoveTowardCell');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, [2, 0], 1);

    expect($state->golem['cell'])->toBe([2, 0])
        ->and($state->wanderers['wanderer_a']['cell'])->toBe([2, 0])
        ->and(collect($events)->pluck('type'))->toContain('wanderer_moved');
});

test('Shamlessness ignores any attempt to gain a Shame token', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'shamlessness'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyShame');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a');

    expect($state->wanderers['wanderer_a']['shame'])->toBe(0);
});

test('Creative alterations lets a Relic equip into any slot', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'creative-alterations'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped, 'holding' => ['burial-robes'],
    ])]);

    // burial-robes is a Body Relic; equip it into "bag" instead.
    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => 'burial-robes', 'slot' => 'bag']);

    expect($state->wanderers['wanderer_a']['equipped']['bag'])->toBe('burial-robes');
});

test('Without Creative alterations, a Relic still can\'t go into the wrong slot', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['holding' => ['burial-robes']])]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => 'burial-robes', 'slot' => 'bag']))
        ->toThrow(InvalidMoveException::class);
});

test('Divine Icon boosts any Relic heal used in its holder\'s space by 3', function () {
    $state = startedGame();
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'bag' => 'divine-icon', 'body' => 'burial-robes',
    ];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5]);
    $wanderers['wanderer_b']['hp'] = 5;
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'burial-robes']);

    // Burial robes heals everyone sharing the caster's space to full
    // (15), so the +3 boost is absorbed by the 15 cap — use a lower
    // cap-free case instead via divine-healing-oil to see the +3 bare.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(15);
});

test('Divine Icon adds exactly +3 on top of a heal that would not otherwise hit the cap', function () {
    $state = startedGame();
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'bag' => 'divine-icon', 'body' => 'divine-healing-oil',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-healing-oil']);

    // Divine healing oil's normal +3, plus Divine Icon's own +3 => +6.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(11);
});

test('Poison Blouse taps the Golem\'s draw pile when it attacks its holder', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'poison-blouse'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemAttackHere');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 2);

    $tappedCount = count(array_filter($state->golem['draw_pile'], fn ($c) => $c['tapped']));
    expect($tappedCount)->toBe(1);
});

test('Divine Poison Inversion heals 2 after the Golem attacks its holder', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'divine-poison-inversion'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemAttackHere');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 2);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10); // -2 then +2
});

test('inscribe-7 adds a 🌑 Glyph and trashes itself when the Golem attacks its holder', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'inscribe-7'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemAttackHere');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 2);

    expect($state->golem['glyphs'])->toHaveCount(1)
        ->and(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🌑')
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Buckler uses the Action in the other Hand slot for free when the Golem attacks its holder', function () {
    $state = startedGame(); // wanderer_a shares the Golem's own cell, so the attack actually lands
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'buckler', 'hand_right' => 'divine-healing-oil',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $actionsBefore = $state->wanderers['wanderer_a']['actions_remaining'];

    $reflection = new ReflectionMethod(GameEngine::class, 'golemAttackHere');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 2);

    // Divine healing oil's +3 self-heal fired for free (defaults to
    // self when no Ally target is supplied, same as a normal use) —
    // 5 - 2 (the Golem's own attack) + 3 = 6, and no action was spent.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(6)
        ->and($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBefore)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_healed');
});

test('Cabalist\'s Gown sets Health to 5 and caps it there when equipped', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['holding' => ['cabalists-gown'], 'hp' => 15])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'equip_relic', 'relic_key' => 'cabalists-gown', 'slot' => 'body',
    ]);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_hp_set');

    // Further heals are capped at 5 too.
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'burial-robes']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
});

test('Cabalist\'s Gown prevents any Health loss on a 🌑 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'cursed-tomb']; // produces 🌑
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'cabalists-gown'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 10);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
});

test('Cabalist\'s Gown does not block damage off a 🌑 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // produces 🎨, not 🌑
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'cabalists-gown'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 3);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(2);
});

test('Inverted Glyph heals instead of damaging a Golem attack on a ⏳ space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'sundial']; // produces ⏳
    $state = $state->with(['board' => $board, 'golem' => $state->golemWith(['glyphs' => ['inverted']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 4, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(9)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_healed')
        ->and(collect($events)->pluck('type'))->not->toContain('wanderer_damaged');
});

test('Inverted Glyph does not affect damage off a ⏳ space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // produces 🎨, not ⏳
    $state = $state->with(['board' => $board, 'golem' => $state->golemWith(['glyphs' => ['inverted']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 4, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(1);
});

test('Inverted Glyph does not affect non-Golem damage on a ⏳ space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'sundial']; // produces ⏳
    $state = $state->with(['board' => $board, 'golem' => $state->golemWith(['glyphs' => ['inverted']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 4, false);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(1);
});

test('Golem attacking on a ⏳ space with Inverted logs the heal, not a contradictory damage line', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'sundial']; // produces ⏳, Golem's own starting cell
    $state = $state->with(['board' => $board, 'golem' => $state->golemWith(['glyphs' => ['inverted']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemAttackHere');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 4);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(9)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_healed')
        ->and(collect($events)->pluck('type'))->not->toContain('wanderer_damaged');
});

test('Magnetic mail moves its holder into an adjacent Ally\'s Monolith Shard space, any time', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'magnetic-mail'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [2, 0]])]);
    // Note: no end_emperor_phase/active-turn setup at all — this move
    // isn't turn-gated.

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'magnetic_mail_move', 'target_role' => 'wanderer_b',
    ]);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([2, 0])
        ->and(collect($events)->pluck('type'))->toContain('wanderer_moved');
});

test('Magnetic mail requires the Ally\'s space to actually have a Monolith Shard', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'magnetic-mail'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [2, 0]])]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'magnetic_mail_move', 'target_role' => 'wanderer_b']))
        ->toThrow(InvalidMoveException::class);
});

test('Telekinesis moves from a Monolith Shard space to any built space, costing the normal action', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'telekenesis'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'telekinesis_move', 'cell' => [0, 1]]);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([0, 1])
        ->and($state->wanderers['wanderer_a']['actions_remaining'])->toBe(2)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_moved');
});

test('Telekinesis requires actually being on a Monolith Shard space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'telekenesis'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'telekinesis_move', 'cell' => [0, 1]]))
        ->toThrow(InvalidMoveException::class);
});

test('The Monolith within makes its holder\'s space count as having a Monolith Shard', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'the-monolith-within'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'hasMonolithShardFor');
    $reflection->setAccessible(true);

    expect($reflection->invoke(null, $state, 'wanderer_a', [1, 0]))->toBeTrue()
        ->and($reflection->invoke(null, $state, 'wanderer_b', [1, 0]))->toBeFalse();
});

test('Golem insight heals 1 before a Golem attack, once per round', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'golem-insight'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 3, true);

    // +1 healed before the hit, then -3 => net 8.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(8)
        ->and($state->wanderers['wanderer_a']['golem_insight_used_this_round'])->toBeTrue();

    // Already used this round — no second heal.
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 3, true);
    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
});

test('Ephemeral protection negates a Golem attack while on a Monolith Shard, then trashes itself and the Shard', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'ephemeral-protection'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);
    $poolBefore = $state->infrastructurePool['monolith_shard'];

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 5, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and($state->board['1,0']['infrastructure'])->not->toContain('monolith_shard')
        ->and($state->infrastructurePool['monolith_shard'])->toBe($poolBefore + 1)
        ->and($state->prophecy)->toBe(1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Ephemeral protection does nothing off a Monolith Shard', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'ephemeral-protection'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 5, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5)
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBe('ephemeral-protection');
});

test('Divine sack cloth of sacrifice redirects half an Ally\'s damage to its holder', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_b']['equipped'], 'body' => 'divine-sack-cloth-of-sacrifice'];
    $wanderers = $state->wanderersWith('wanderer_b', ['equipped' => $equipped, 'hp' => 10]);
    $wanderers['wanderer_a']['hp'] = 10;
    $state = $state->with(['wanderers' => $wanderers]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 6);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(7) // -3
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(7); // -3
});

test('Guide rope brings other Wanderers along for free when moving', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'guide-rope'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [1, 0]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'S']);

    expect($state->wanderers['wanderer_a']['cell'])->toBe($state->wanderers['wanderer_b']['cell']);
});

test('Rally heals every Ally by 1 after its holder moves', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'rally'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped]);
    $wanderers['wanderer_b']['hp'] = 10;
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'S']);

    expect($state->wanderers['wanderer_b']['hp'])->toBe(11);
});

test('Laborer\'s Jacket moves every Ally to any space, once per round, while on a 🤝 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'town-square'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'laborers-jacket'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'laborers_jacket_move_allies',
        'destinations' => ['wanderer_b' => [0, 0]],
    ]);

    expect($state->wanderers['wanderer_b']['cell'])->toBe([0, 0])
        ->and($state->wanderers['wanderer_a']['laborers_jacket_used_this_round'])->toBeTrue()
        ->and(collect($events)->pluck('type'))->toContain('wanderer_moved');

    expect(fn () => GameEngine::apply($state, 'wanderer_a', [
        'type' => 'laborers_jacket_move_allies',
        'destinations' => ['wanderer_b' => [1, 1]],
    ]))->toThrow(InvalidMoveException::class);
});

test('Laborer\'s Jacket requires standing on a space that produces 🤝', function () {
    $state = startedGame();
    $board = $state->board;
    // Pin the hub to a non-🤝 Location explicitly — a real random board
    // could otherwise deal Town square or Golem sanctuary there, both of
    // which produce 🤝 and would make this negative test flaky.
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel'];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'laborers-jacket'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', [
        'type' => 'laborers_jacket_move_allies',
        'destinations' => ['wanderer_b' => [0, 0]],
    ]))->toThrow(InvalidMoveException::class);
});

test('An expansive mind grants two extra Mind slots, usable only while it occupies mind itself', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'an-expansive-mind'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped, 'holding' => ['shamlessness'],
    ])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => 'shamlessness', 'slot' => 'mind_2']);

    expect($state->wanderers['wanderer_a']['equipped']['mind_2'])->toBe('shamlessness');
});

test('mind_2/mind_3 reject equipping without An expansive mind occupying mind', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['holding' => ['shamlessness']])]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => 'shamlessness', 'slot' => 'mind_2']))
        ->toThrow(InvalidMoveException::class);
});

test('Emperor\'s Knowledge refunds the move\'s action for every 2 equipped Emperor\'s Relics', function () {
    $state = startedGame();
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'body' => 'emperors-robes', 'mind' => 'emperors-diary', 'hand_left' => 'emperors-knowledge',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $actionsBefore = $state->wanderers['wanderer_a']['actions_remaining'];

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => 'S']);

    // 3 Emperor's Relics equipped (including Knowledge itself) => +1
    // refunded action, net unchanged.
    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBefore);
});

test('Poison Charm reduces the Golem\'s next attack card by 3, for every Wanderer it hits', function () {
    $state = startedGame(); // both Wanderers + Golem share the hub
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'poison-charm'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10]);
    $wanderers['wanderer_b']['hp'] = 10;
    $state = $state->with(['wanderers' => $wanderers]);

    $damage = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $damage->setAccessible(true);
    [$state] = $damage->invoke(null, $state, 'wanderer_a', 1, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(9) // this hit itself isn't reduced
        ->and($state->golem['armed_attack_reduction'])->toBe(3);

    // Fire storm: flat 👊3 to everyone on/adjacent to the Golem, no
    // movement involved — a deterministic next card to verify the -3
    // reduction actually lands on.
    $resolve = new ReflectionMethod(GameEngine::class, 'resolveOrSkipTappedCard');
    $resolve->setAccessible(true);
    [$state] = $resolve->invoke(null, $state, ['key' => 'fire-storm', 'tapped' => false]);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(9) // 3 - 3 reduction = 0 damage
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(10) // same
        ->and($state->golem['active_attack_reduction'])->toBe(0); // cleared once the card finished
});

test('Icon of the resurrected grants 2 Prophecy if every mat slot is Bound at game end', function () {
    $state = startedGame();
    $equipped = [
        'body' => 'burial-robes', 'mind' => 'dark-limitations',
        'hand_left' => 'dark-sacrifice', 'hand_right' => 'dark-sigil',
        'bag' => 'icon-of-the-resurrected',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(2)
        ->and(collect($events)->pluck('type'))->toContain('prophecy_gained');
});

test('Icon of the resurrected does not score if any slot is unbound', function () {
    $state = startedGame();
    $equipped = [
        'body' => 'burial-robes', 'mind' => null,
        'hand_left' => 'dark-sacrifice', 'hand_right' => 'dark-sigil',
        'bag' => 'icon-of-the-resurrected',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(0)
        ->and($events)->toBe([]);
});

test('Emperor\'s Timepiece swaps roles, trashes itself, and grants 1 Prophecy once all 5 Emperor\'s Relics are equipped', function () {
    $state = startedGame();
    $equipped = [
        'body' => 'emperors-robes', 'mind' => 'emperors-knowledge',
        'hand_left' => 'emperors-diary', 'hand_right' => 'emperors-staff',
        'bag' => 'emperors-timepiece',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'emperors-timepiece']);

    expect($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and($state->prophecy)->toBe(1)
        ->and(collect($events)->pluck('type'))->toContain('emperor_timepiece_swap');
    $swap = collect($events)->firstWhere('type', 'emperor_timepiece_swap');
    expect($swap['payload'])->toBe(['new_emperor_role' => 'wanderer_a', 'new_wanderer_role' => 'emperor']);
});

test('Emperor\'s Timepiece does nothing with fewer than all 5 Emperor\'s Relics equipped', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'emperors-timepiece', 'body' => 'emperors-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'emperors-timepiece']);

    expect($state->wanderers['wanderer_a']['equipped']['bag'])->toBe('emperors-timepiece')
        ->and($state->prophecy)->toBe(0)
        ->and(collect($events)->pluck('type'))->not->toContain('emperor_timepiece_swap');
});

test('finding Rift in time parks the choice on pendingRiftInTime instead of resolving immediately', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['inverted']])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'resolveEventRelic');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 'rift-in-time', []);

    expect($state->pendingRiftInTime)->toBe('wanderer_a')
        ->and($state->golem['glyphs'])->toBe(['inverted']) // unchanged — not yet resolved
        ->and(collect($events)->pluck('type'))->toContain('rift_in_time_offered');
});

test('resolve_rift_in_time discards a chosen face-up Glyph and replaces it with another of the same type', function () {
    $state = startedGame();
    $glyphDeck = $state->glyphDeck;
    $chosen = $glyphDeck[0];
    // The forced face-up Glyph has to actually leave the deck too — a
    // real game state never has the same Glyph key both on the mat and
    // still in the deck.
    $state = $state->with([
        'golem' => $state->golemWith(['glyphs' => [$chosen]]),
        'glyphDeck' => array_values(array_slice($glyphDeck, 1)),
        'pendingRiftInTime' => 'wanderer_a',
    ]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'resolve_rift_in_time', 'glyph_key' => $chosen]);

    expect($state->pendingRiftInTime)->toBeNull()
        ->and($state->golem['glyphs'])->not->toContain($chosen)
        ->and($state->golem['glyphs'])->toHaveCount(1)
        ->and($state->glyphDiscardPile)->toContain($chosen)
        ->and(collect($events)->pluck('type'))->toContain('glyph_discarded', 'glyph_added');
});

test('Rift in time does nothing immediately if there are no face-up Glyphs', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => []])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'resolveEventRelic');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 'rift-in-time', []);

    expect($state->pendingRiftInTime)->toBeNull()
        ->and($events)->toBe([]);
});

test('resolve_rift_in_time does nothing for a glyph_key that is not actually active', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['inverted']]), 'pendingRiftInTime' => 'wanderer_a']);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'resolve_rift_in_time', 'glyph_key' => 'nonexistent']);

    expect($state->pendingRiftInTime)->toBeNull()
        ->and($state->golem['glyphs'])->toBe(['inverted'])
        ->and($events)->toBe([]);
});

test('Prohibition of inscribing gives the actor who added a Glyph 1 Shame token, while equipped by anyone', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_b']['equipped'], 'hand_left' => 'prohibition-of-inscribing'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'addGlyphOfType');
    $reflection->setAccessible(true);
    $type = GlyphCatalog::find($state->glyphDeck[0])?->type;
    [$state, $events] = $reflection->invoke(null, $state, $type, 'wanderer_a');

    expect(collect($events)->pluck('type'))->toContain('wanderer_shamed')
        ->and($state->wanderers['wanderer_a']['shame'])->toBe(1);
});

test('Prohibition of inscribing gives the Golem 1 Shame instead, for a Golem-attributed Glyph add (no actorRole)', function () {
    // Confirmed in production: equipped, then the Golem added
    // "Vindictive" via a card with no actorRole, and gained no Shame
    // at all — the Golem has its own Shame counter (capped at 3, same
    // as everywhere else it's gained), not a Shame status token.
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_b']['equipped'], 'hand_left' => 'prohibition-of-inscribing'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equipped])]);
    $shameBefore = $state->golem['shame'];

    $reflection = new ReflectionMethod(GameEngine::class, 'addGlyphOfType');
    $reflection->setAccessible(true);
    $type = GlyphCatalog::find($state->glyphDeck[0])?->type;
    [$state, $events] = $reflection->invoke(null, $state, $type);

    expect($state->golem['shame'])->toBe($shameBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('golem_shame_gained')
        ->and(collect($events)->pluck('type'))->not->toContain('wanderer_shamed');
});

test('Prohibition of inscribing caps the Golem\'s Shame gain at 3', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_b']['equipped'], 'hand_left' => 'prohibition-of-inscribing'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $equipped])]);
    $state = $state->with(['golem' => $state->golemWith(['shame' => 3])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'addGlyphOfType');
    $reflection->setAccessible(true);
    $type = GlyphCatalog::find($state->glyphDeck[0])?->type;
    [$state, $events] = $reflection->invoke(null, $state, $type);

    expect($state->golem['shame'])->toBe(3)
        ->and(collect($events)->pluck('type'))->not->toContain('golem_shame_gained');
});

test('a Passive Relic cannot be "used" — it is rejected outright, without spending the action', function () {
    // Confirmed in production: the UI showed a "Use" button for
    // Prohibition of inscribing (a Passive Relic) and let it burn
    // through actions for nothing, since resolveRelicAction's match
    // has no case for it and silently falls through to a no-op.
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'prohibition-of-inscribing'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $actionsBefore = $state->wanderers['wanderer_a']['actions_remaining'];

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'prohibition-of-inscribing']))
        ->toThrow(InvalidMoveException::class, 'is a passive Relic');

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBefore);
});

test('Farmer\'s insight doubles the Fruit tree action bonus for a Wanderer starting their turn there', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['fruit_tree']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'farmers-insight'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    // Base 3 + 2 from the doubled Fruit tree bonus (normally +1).
    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(5);
});

test('Pen grants 3 Prophecy at end of game if all 3 active Glyphs are non-👺 and Pen is built', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'pen'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['inverted', 'paradoxical', 'perplexing']])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(3)
        ->and(collect($events)->pluck('type'))->toContain('prophecy_gained');
});

test('Pen scoring counts non-👺 Glyphs, not total active, even past the normal 3-Glyph cap', function () {
    // GOLEM_MAX_GLYPHS is 3, so 4 active glyphs can't happen through
    // normal play — this is a defensive check that the >= 3 threshold
    // itself is about non-👺 count, not "every active slot is non-👺",
    // in case the cap ever changes again.
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'pen'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['inverted', 'paradoxical', 'perplexing', 'mobile']])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(3)
        ->and(collect($events)->pluck('type'))->toContain('prophecy_gained');
});

test('Pen does not score with only 2 non-👺 Glyphs active', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'pen'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['inverted', 'paradoxical', 'mobile']])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(0)
        ->and($events)->toBe([]);
});

test('Pen does not score if Pen was never built', function () {
    $state = startedGame();
    $board = $state->board;
    foreach ($board as $key => $entry) {
        if ($entry['location_key'] === 'pen') {
            $board[$key] = [...$entry, 'location_key' => 'artists-hovel'];
        }
    }
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['inverted', 'paradoxical', 'perplexing', 'parallel']])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(0)
        ->and($events)->toBe([]);
});

test('Bazaar grants 1 Prophecy per 4 Relics tucked there at end of game', function () {
    $state = startedGame();
    $board = $state->board;
    // Bazaar can only ever be built once in a real game (it's a unique
    // Location in the deck), but startedGame()'s random starting 6
    // could otherwise already include one elsewhere on the board —
    // scrub that first so the scoring loop can't find the wrong one.
    foreach ($board as $key => $entry) {
        if ($entry['location_key'] === 'bazaar') {
            $board[$key] = [...$entry, 'location_key' => 'artists-hovel'];
        }
    }
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'bazaar', 'tucked' => array_fill(0, 9, 'golem-whistle')];
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(2) // intdiv(9, 4)
        ->and(collect($events)->pluck('type'))->toContain('prophecy_gained');
});

test('Bazaar does not score with fewer than 4 Relics tucked there', function () {
    $state = startedGame();
    $board = $state->board;
    foreach ($board as $key => $entry) {
        if ($entry['location_key'] === 'bazaar') {
            $board[$key] = [...$entry, 'location_key' => 'artists-hovel'];
        }
    }
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'bazaar', 'tucked' => array_fill(0, 3, 'golem-whistle')];
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(0)
        ->and($events)->toBe([]);
});

test('Cathedral grants 1 Prophecy per tucked Relic with "Divine" in its name', function () {
    $state = startedGame();
    $board = $state->board;
    // Cathedral, like Bazaar, can only ever be built once in a real
    // game — scrub any that landed on startedGame()'s random starting
    // 6 so the scoring loop can't find the wrong one.
    foreach ($board as $key => $entry) {
        if ($entry['location_key'] === 'cathedral') {
            $board[$key] = [...$entry, 'location_key' => 'artists-hovel'];
        }
    }
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'cathedral', 'tucked' => ['divine-robes', 'divine-aura', 'golem-whistle']];
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(2) // divine-robes, divine-aura — golem-whistle doesn't count
        ->and(collect($events)->pluck('type'))->toContain('prophecy_gained');
});

test('Cathedral does not score when nothing tucked there has "Divine" in its name', function () {
    $state = startedGame();
    $board = $state->board;
    foreach ($board as $key => $entry) {
        if ($entry['location_key'] === 'cathedral') {
            $board[$key] = [...$entry, 'location_key' => 'artists-hovel'];
        }
    }
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'cathedral', 'tucked' => ['golem-whistle']];
    $state = $state->with(['board' => $board]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyEndOfGameScoring');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state);

    expect($state->prophecy)->toBe(0)
        ->and($events)->toBe([]);
});
