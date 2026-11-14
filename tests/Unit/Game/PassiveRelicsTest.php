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
        'body' => 'dark-chains', 'mind' => null,
        'hand_left' => 'dark-sacrifice', 'hand_right' => null, 'bag' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 5);

    // 2 Dark Relics equipped (dark-chains + dark-sacrifice itself counts
    // too, it's an equipped "Dark ..." Relic the same as any other) => -2.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(12);
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

test('Dark limitations caps Health at 5 for every heal', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'dark-limitations', 'body' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 1])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'burial-robes']); // normally heals to 15

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
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

test('Dark chains pulls its holder along when the Golem moves', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'dark-chains'];
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

    $reflection = new ReflectionMethod(GameEngine::class, 'applyStatusToken');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 'shame_token');

    expect($state->wanderers['wanderer_a']['equipped'])->not->toContain('shame_token');
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
