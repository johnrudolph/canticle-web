<?php

use App\Game\GameEngine;
use App\Game\InvalidMoveException;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Poof removes the Golem and skips the Golem phase forever, once all 12 Monolith Shards are within 2 spaces', function () {
    $state = startedGame();
    $board = $state->board;
    foreach (array_keys($board) as $key) {
        $board[$key] = [...$board[$key], 'infrastructure' => ['monolith_shard', 'monolith_shard']];
    }
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'poof'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'poof']);

    expect($state->golem['cell'])->toBeNull()
        ->and($state->golemSkipped)->toBeTrue()
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Poof is rejected outright short of all 12 Monolith Shards nearby', function () {
    $state = startedGame();
    $board = $state->board;
    $cellKeys = array_keys($board);
    foreach ($cellKeys as $i => $key) {
        // 11 total across the 6 cells instead of 12.
        $board[$key] = [...$board[$key], 'infrastructure' => $i === 0 ? ['monolith_shard'] : ['monolith_shard', 'monolith_shard']];
    }
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'poof'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'poof']))
        ->toThrow(InvalidMoveException::class);

    expect($state->golem['cell'])->not->toBeNull()
        ->and($state->golemSkipped)->toBeFalse();
});

test('Scapegoat\'s catharsis damages Allies and trashes itself plus all 4 Dark Relics', function () {
    $state = startedGame();
    $equipped = [
        'body' => 'dark-chains', 'mind' => 'dark-limitations',
        'hand_left' => 'dark-sacrifice', 'hand_right' => 'dark-sigil', 'bag' => 'scapegoats-catharsis',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'scapegoats-catharsis']);

    expect($state->wanderers['wanderer_b']['hp'])->toBe(5) // the Ally, sharing the hub cell
        ->and($state->wanderers['wanderer_a']['hp'])->toBe(15) // the caster is not an "Ally" to themselves
        ->and($state->wanderers['wanderer_a']['equipped'])->toBe(['body' => null, 'mind' => null, 'hand_left' => null, 'hand_right' => null, 'bag' => null])
        ->and(collect($events)->where('type', 'relic_trashed'))->toHaveCount(5);
});

test('Scapegoat\'s catharsis is rejected outright without all 4 Dark Relics equipped', function () {
    $state = startedGame();
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'body' => 'dark-chains', 'bag' => 'scapegoats-catharsis',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'scapegoats-catharsis']))
        ->toThrow(InvalidMoveException::class);

    expect($state->wanderers['wanderer_a']['equipped']['bag'])->toBe('scapegoats-catharsis')
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(15);
});

test('Silent hunting cloak taps the Golem\'s next 2 untapped cards on a 🐾 space shared with the Golem', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'pen']; // produces 🐾
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'silent-hunting-cloak'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'silent-hunting-cloak']);

    $tappedCount = count(array_filter($state->golem['draw_pile'], fn ($c) => $c['tapped']));

    expect($tappedCount)->toBe(2)
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBe('silent-hunting-cloak');
});

test('Silent hunting cloak is rejected outright off a 🐾 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // produces 🎨
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'silent-hunting-cloak'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'silent-hunting-cloak']))
        ->toThrow(InvalidMoveException::class);

    $tappedCount = count(array_filter($state->golem['draw_pile'], fn ($c) => $c['tapped']));
    expect($tappedCount)->toBe(0);
});

test('Jericho inscription destroys a Wall when the Golem is on the far side and the instruments are adjacent to it', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'infrastructure' => ['walls']];
    $board['3,-1'] = ['location_key' => 'cathedral', 'placed_round' => 2, 'relics' => [], 'infrastructure' => []];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['cell' => [3, -1]])]);
    $equipped = [
        'hand_left' => 'jericho-inscription', 'mind' => 'lute',
        'hand_right' => 'pipe', 'bag' => 'tabor-drum', 'body' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $wallsPoolBefore = $state->infrastructurePool['walls'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'jericho-inscription']);

    expect($state->board['2,0']['infrastructure'])->toBe([])
        ->and($state->infrastructurePool['walls'])->toBe($wallsPoolBefore + 1)
        ->and($state->golem['glyphs'])->toHaveCount(1)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Jericho inscription is rejected outright if the Golem is not on the far side of the Wall', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'infrastructure' => ['walls']];
    $state = $state->with(['board' => $board]);
    $equipped = [
        'hand_left' => 'jericho-inscription', 'mind' => 'lute',
        'hand_right' => 'pipe', 'bag' => 'tabor-drum', 'body' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'jericho-inscription']))
        ->toThrow(InvalidMoveException::class);

    expect($state->board['2,0']['infrastructure'])->toBe(['walls'])
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('jericho-inscription');
});

test('Past troubles reshuffles the active Glyphs and discard pile, keeping the total count the same', function () {
    $state = startedGame();
    $golem = $state->golem;
    $golem['glyphs'] = ['enchanted', 'fearful'];
    $state = $state->with(['golem' => $golem, 'glyphDiscardPile' => ['buoyant', 'empathetic', 'mobile']]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'past-troubles'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'past-troubles']);

    $totalAfter = count($state->golem['glyphs']) + count($state->glyphDiscardPile);

    expect($totalAfter)->toBe(5)
        ->and(count($state->golem['glyphs']))->toBeLessThanOrEqual(4)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('past-troubles') // not trashed
        ->and(collect($events)->pluck('type'))->toContain('glyphs_reshuffled');
});

test('Time Paradox resolves 2 additional cards from the bottom of the draw pile, not the revealed row', function () {
    $state = startedGame();
    $golem = $state->golem;
    $golem['revealed'] = [
        ['key' => 'storm', 'tapped' => false],
        ['key' => 'spew', 'tapped' => false],
        ['key' => 'time-paradox', 'tapped' => false],
    ];
    $golem['draw_pile'] = [
        ['key' => 'scar', 'tapped' => false],
        ['key' => 'penitence', 'tapped' => false],
        ['key' => 'dissent', 'tapped' => false],
    ];
    $state = $state->with(['golem' => $golem]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemPlayCards');
    $reflection->setAccessible(true);
    [$newState, $events] = $reflection->invoke(null, $state, 1);

    // The 1 normal play resolves Time Paradox (right end of revealed);
    // its effect then resolves 2 more from the BOTTOM of draw_pile
    // (dissent, then penitence — not "scar," which was on top). All 3
    // land in the discard pile, and the refill afterward pulls "scar"
    // (the only card left) into the revealed row.
    expect(collect($events)->pluck('type'))->toContain('golem_card_resolved')
        ->and($newState->golem['discard_pile'])->toHaveCount(3)
        ->and($newState->golem['discard_pile'])->toContain('time-paradox')
        ->and($newState->golem['discard_pile'])->toContain('dissent')
        ->and($newState->golem['discard_pile'])->toContain('penitence')
        ->and($newState->golem['draw_pile'])->toBe([])
        ->and(collect($newState->golem['revealed'])->pluck('key'))->toContain('scar');
});
