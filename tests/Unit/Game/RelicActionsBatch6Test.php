<?php

use App\Game\GameEngine;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Cabalist brand discards chosen Glyphs, makes the Golem play 3 cards, then trashes', function () {
    $state = startedGame();
    $golem = $state->golem;
    $golem['glyphs'] = ['enchanted', 'fearful'];
    $state = $state->with(['golem' => $golem]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'cabalist-brand'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $discardCountBefore = count($state->golem['discard_pile']);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'cabalist-brand', 'glyph_keys' => ['enchanted'],
    ]);

    // 'enchanted' was discarded; 'fearful' survives. (The 3 subsequent
    // Golem card plays can themselves add a new Glyph — that's a real
    // interaction, not asserted here since which card resolves is
    // non-deterministic.)
    expect($state->golem['glyphs'])->toContain('fearful')
        ->and($state->golem['glyphs'])->not->toContain('enchanted')
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('glyph_discarded')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed')
        ->and(collect($events)->pluck('type'))->toContain('golem_card_resolved')
        // 3 cards were popped off the revealed row, played, and discarded.
        ->and(count($state->golem['discard_pile']))->toBe($discardCountBefore + 3);
});

test('Cabalist brand does nothing off the Golem\'s space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'cabalist-brand'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 0]])]);

    $revealedBefore = $state->golem['revealed'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'cabalist-brand', 'glyph_keys' => ['enchanted'],
    ]);

    expect($state->golem['revealed'])->toBe($revealedBefore)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('cabalist-brand')
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});

test('Cabalist brand allows discarding zero Glyphs', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'cabalist-brand'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'cabalist-brand', 'glyph_keys' => [],
    ]);

    // Discarding zero Glyphs is a valid no-op for that part of the
    // effect — the 3 subsequent Golem card plays can still add one of
    // their own, so this only checks nothing was discarded and the
    // Relic still trashes itself.
    expect(collect($events)->pluck('type'))->not->toContain('glyph_discarded')
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});
