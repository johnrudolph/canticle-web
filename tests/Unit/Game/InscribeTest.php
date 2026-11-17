<?php

use App\Game\Catalog\GlyphCatalog;
use App\Game\GameEngine;
use App\Game\GameState;
use App\Game\InvalidMoveException;

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

test('inscribe, inscribe-2, and inscribe-3 add a ⏳ Glyph while on the Golem\'s space, gain 📜, then trash', function (string $key) {
    $state = startedGame();
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, $key);

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('⏳')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed')
        ->and(collect($events)->pluck('type'))->toContain('prophecy_gained');
})->with(['inscribe', 'inscribe-2', 'inscribe-3']);

test('inscribe-4 requires the Prison, 0 Shame, and 3+ Golem Shame, then adds a ⚖️ Glyph and gains 3 📜', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'prison'];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['shame' => 3])]);
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, 'inscribe-4');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('⚖️')
        ->and($state->prophecy)->toBe($prophecyBefore + 3)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-4 is rejected outright outside the Prison, without spending the action', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // not the Prison
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['shame' => 3])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'inscribe-4'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $actionsBefore = $state->wanderers['wanderer_a']['actions_remaining'];

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'inscribe-4']))
        ->toThrow(InvalidMoveException::class, 'Inscribe requires');

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBefore);
});

test('inscribe-5 requires 0 Shame and the Golem\'s space, then adds a ⚖️ Glyph and gains 📜', function () {
    $state = startedGame();
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, 'inscribe-5');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('⚖️')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-5 is rejected outright with any Shame, without spending the action', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'shame_token', 'hand_left' => 'inscribe-5'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'inscribe-5']))
        ->toThrow(InvalidMoveException::class, 'Inscribe requires');
});

test('inscribe-6 requires a Monolith Shard and the Golem\'s space, then adds a ✨ Glyph and gains 📜', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, 'inscribe-6');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('✨')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-6 is rejected outright without a Monolith Shard present, without spending the action', function () {
    // Confirmed in production, game WNNDOZ: used while standing on the
    // Golem's space but with no Monolith Shard anywhere on the board —
    // silently spent the action and did nothing, with no indication
    // the condition wasn't met.
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'inscribe-6'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $actionsBefore = $state->wanderers['wanderer_a']['actions_remaining'];

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'inscribe-6']))
        ->toThrow(InvalidMoveException::class, 'Inscribe requires');

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBefore);
});

test('inscribe-6 fizzles (no Glyph, no 📜, not trashed) if every ✨ Glyph is already drawn, even with its condition met', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'inscribe-6'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    // Every ✨ Glyph already drawn (active or discarded) — none left in the deck.
    $deck = array_values(array_filter($state->glyphDeck, fn ($key) => GlyphCatalog::find($key)?->type !== '✨'));
    $state = $state->with(['glyphDeck' => $deck]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $prophecyBefore = $state->prophecy;

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'inscribe-6']);

    expect($state->golem['glyphs'])->toBe([])
        ->and($state->prophecy)->toBe($prophecyBefore)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('inscribe-6')
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('inscribe-7 cannot be used directly — it only triggers automatically when the Golem attacks its holder', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'inscribe-7'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'inscribe-7']))
        ->toThrow(InvalidMoveException::class, 'triggers automatically');
});

test('Golem attack reactions add inscribe-7\'s 🌑 Glyph, gain 📜, and trash it', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'inscribe-7'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $prophecyBefore = $state->prophecy;

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, 'wanderer_a', 2, true);

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🌑')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-8 adds a 🎨 Glyph while on the Golem\'s space, gains 📜, then trashes', function () {
    $state = startedGame();
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, 'inscribe-8', 'bag');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🎨')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-9 adds a 🐾 Glyph while on the Golem\'s space, gains 📜, then trashes', function () {
    $state = startedGame();
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, 'inscribe-9', 'bag');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🐾')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-10 adds a 📖 Glyph while on the Golem\'s space, gains 📜, then trashes', function () {
    $state = startedGame();
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, 'inscribe-10');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('📖')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-11 requires every Wanderer on the Golem\'s space, then adds a 🤝 Glyph and gains 📜', function () {
    $state = startedGame(); // both Wanderers already share the Golem's hub cell
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, 'inscribe-11');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🤝')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('inscribe-11 is rejected outright if any Wanderer is elsewhere, without spending the action', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 0]])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'inscribe-11'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'inscribe-11']))
        ->toThrow(InvalidMoveException::class, 'Inscribe requires');
});

test('inscribe-12 requires the Golem\'s space and every Wanderer 2+ apart, adds a 🤝 Glyph, gains 📜, and does not trash', function () {
    $state = startedGame();
    // Extend the board past the starting 6 cells so a Wanderer can
    // actually be 2 hops from the hub — every starting cell is directly
    // adjacent to [1,0].
    $board = $state->board;
    $board['3,0'] = ['location_key' => 'cathedral', 'placed_round' => 2, 'relics' => [], 'infrastructure' => []];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [3, 0]])]);
    $prophecyBefore = $state->prophecy;

    [$state, $events] = equipAndUseRelic($state, 'inscribe-12');

    expect(GlyphCatalog::find($state->golem['glyphs'][0])->type)->toBe('🤝')
        ->and($state->prophecy)->toBe($prophecyBefore + 1)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('inscribe-12')
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('inscribe-12 is rejected outright if Wanderers are within 2 spaces of each other, without spending the action', function () {
    $state = startedGame(); // both on the same hub cell — distance 0
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'inscribe-12'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'inscribe-12']))
        ->toThrow(InvalidMoveException::class, 'Inscribe requires');
});
