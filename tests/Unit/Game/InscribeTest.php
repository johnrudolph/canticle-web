<?php

use App\Game\Catalog\GlyphCatalog;
use App\Game\GameEngine;
use App\Game\GameState;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.
//
// The "Inscribe" name is reprinted 12 times in relics.json, but each
// instance is a mechanically distinct card (different slot, condition,
// and Glyph color) — resolveRelicAction dispatches these on the full
// disambiguated key (inscribe, inscribe-2, ... inscribe-12), not the
// usual suffix-stripped baseKey. These tests exercise each one.

function equipAndUseRelic(GameState $state, string $relicKey, string $slot = 'hand_left'): array
{
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], $slot => $relicKey];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    return GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => $relicKey]);
}

test('inscribe, inscribe-2, and inscribe-3 add a ⏳ Glyph while on the Golem\'s space, then trash', function (string $key) {
    $state = startedGame();

    [$state, $events] = equipAndUseRelic($state, $key);

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('⏳')
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
})->with(['inscribe', 'inscribe-2', 'inscribe-3']);

test('inscribe-4 requires the Prison, 0 Shame, and 3+ Golem Shame, then adds a ⚖️ Glyph', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'prison'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['shame' => 3])]);

    [$state, $events] = equipAndUseRelic($state, 'inscribe-4');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('⚖️')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-4 does nothing outside the Prison', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['shame' => 3])]);

    [$state, $events] = equipAndUseRelic($state, 'inscribe-4');

    expect($state->golem['glyphs'])->toBe([])
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('inscribe-5 requires 0 Shame and the Golem\'s space, then adds a ⚖️ Glyph', function () {
    $state = startedGame();

    [$state, $events] = equipAndUseRelic($state, 'inscribe-5');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('⚖️')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-5 does nothing with any Shame', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'shame_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state, $events] = equipAndUseRelic($state, 'inscribe-5');

    expect($state->golem['glyphs'])->toBe([])
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('inscribe-6 requires a Monolith Shard and the Golem\'s space, then adds a ✨ Glyph', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);

    [$state, $events] = equipAndUseRelic($state, 'inscribe-6');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('✨')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-7 is a no-op (reactive "when the Golem hits you" has no trigger hook yet)', function () {
    $state = startedGame();

    [$state, $events] = equipAndUseRelic($state, 'inscribe-7');

    expect($state->golem['glyphs'])->toBe([])
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('inscribe-7')
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('inscribe-8 adds a 🎨 Glyph while on the Golem\'s space, then trashes', function () {
    $state = startedGame();

    [$state, $events] = equipAndUseRelic($state, 'inscribe-8', 'bag');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🎨')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-9 adds a 🐾 Glyph while on the Golem\'s space, then trashes', function () {
    $state = startedGame();

    [$state, $events] = equipAndUseRelic($state, 'inscribe-9', 'bag');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🐾')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-10 adds a 📖 Glyph while on the Golem\'s space, then trashes', function () {
    $state = startedGame();

    [$state, $events] = equipAndUseRelic($state, 'inscribe-10');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('📖')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-11 requires every Wanderer on the Golem\'s space, then adds a 🤝 Glyph', function () {
    $state = startedGame(); // both Wanderers already share the Golem's hub cell

    [$state, $events] = equipAndUseRelic($state, 'inscribe-11');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🤝')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-11 does nothing if any Wanderer is elsewhere', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 0]])]);

    [$state, $events] = equipAndUseRelic($state, 'inscribe-11');

    expect($state->golem['glyphs'])->toBe([])
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('inscribe-12 requires the Golem\'s space and every Wanderer 2+ apart, adds a 🤝 Glyph, and does not trash', function () {
    $state = startedGame();
    // Extend the board past the starting 6 cells so a Wanderer can
    // actually be 2 hops from the hub — every starting cell is directly
    // adjacent to [1,0].
    $board = $state->board;
    $board['3,0'] = ['location_key' => 'cathedral', 'placed_round' => 2, 'relics' => [], 'infrastructure' => []];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [3, 0]])]);

    [$state, $events] = equipAndUseRelic($state, 'inscribe-12');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🤝')
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('inscribe-12')
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('inscribe-12 does nothing if Wanderers are within 2 spaces of each other', function () {
    $state = startedGame(); // both on the same hub cell — distance 0

    [$state, $events] = equipAndUseRelic($state, 'inscribe-12');

    expect($state->golem['glyphs'])->toBe([])
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});
