<?php

use App\Game\GameEngine;
use App\Game\GameSetup;
use App\Game\GameStateRepository;
use App\Models\Game;

test('persist/load round-trips every GameState field, including glyphDiscardPile/golemSkipped/trashedRelics', function () {
    $game = Game::factory()->create();
    $state = GameSetup::create(2)->with([
        'glyphDiscardPile' => ['inverted'],
        'golemSkipped' => true,
        'trashedRelics' => ['burial-robes', 'last-rites'],
    ]);

    GameStateRepository::persist($game, $state);
    $reloaded = GameStateRepository::load($game->fresh());

    expect($reloaded->glyphDiscardPile)->toBe(['inverted'])
        ->and($reloaded->golemSkipped)->toBeTrue()
        ->and($reloaded->trashedRelics)->toBe(['burial-robes', 'last-rites']);
});

test('persist/load round-trips a fresh default GameState unchanged', function () {
    $game = Game::factory()->create();
    $state = GameSetup::create(2);

    GameStateRepository::persist($game, $state);
    $reloaded = GameStateRepository::load($game->fresh());

    expect($reloaded->glyphDiscardPile)->toBe([])
        ->and($reloaded->golemSkipped)->toBeFalse()
        ->and($reloaded->trashedRelics)->toBe([])
        ->and($reloaded->wandererCount)->toBe($state->wandererCount)
        ->and($reloaded->board)->toBe($state->board);
});

test('load() backfills fields missing from an old-shaped persisted board/wanderers/golem', function () {
    $game = Game::factory()->create();

    // Simulates a game saved before relics_face_up/tucked (board),
    // move_blocked_this_turn/tucked_cards/was_ever_exhausted/
    // golem_insight_used_this_round/laborers_jacket_used_this_round/
    // mind_2/mind_3 (wanderers), and armed_attack_reduction/
    // active_attack_reduction (golem) existed — written directly,
    // bypassing GameStateRepository::persist(), which would never
    // produce this old shape itself.
    $game->update([
        'round' => 1,
        'phase' => 'wanderer',
        'state' => [
            'wanderer_count' => 2,
            'round' => 1,
            'phase' => 'wanderer',
            'board' => [
                '0,0' => ['location_key' => 'artists-hovel', 'placed_round' => 1, 'relics' => [], 'infrastructure' => []],
            ],
            'location_deck' => [],
            'location_hand' => [],
            'location_discard' => [],
            'wanderers' => [
                'wanderer_a' => [
                    'cell' => [0, 0], 'hp' => 15, 'actions_remaining' => 2,
                    'equipped' => ['body' => null, 'mind' => 'creative-alterations', 'hand_left' => null, 'hand_right' => null, 'bag' => null],
                    'holding' => ['artists-insight'],
                ],
                'wanderer_b' => [
                    'cell' => [0, 0], 'hp' => 15, 'actions_remaining' => 2,
                    'equipped' => ['body' => null, 'mind' => null, 'hand_left' => null, 'hand_right' => null, 'bag' => null],
                    'holding' => [],
                ],
            ],
            'golem' => ['cell' => [0, 0], 'shame' => 0, 'glyphs' => [], 'draw_pile' => [], 'revealed' => [], 'discard_pile' => []],
            'infrastructure_pool' => ['monolith_shard' => 12, 'road' => 10, 'walls' => 2, 'fruit_tree' => 2],
            'wanderer_order' => ['wanderer_a', 'wanderer_b'],
            'active_wanderer_index' => 0,
        ],
    ]);

    $state = GameStateRepository::load($game->fresh());

    expect($state->board['0,0']['relics_face_up'])->toBe([])
        ->and($state->board['0,0']['tucked'])->toBe([])
        ->and($state->wanderers['wanderer_a']['move_blocked_this_turn'])->toBeFalse()
        ->and($state->wanderers['wanderer_a']['tucked_cards'])->toBe([])
        ->and($state->wanderers['wanderer_a']['was_ever_exhausted'])->toBeFalse()
        ->and($state->wanderers['wanderer_a']['golem_insight_used_this_round'])->toBeFalse()
        ->and($state->wanderers['wanderer_a']['laborers_jacket_used_this_round'])->toBeFalse()
        ->and($state->wanderers['wanderer_a']['equipped']['mind_2'])->toBeNull()
        ->and($state->wanderers['wanderer_a']['equipped']['mind_3'])->toBeNull()
        ->and($state->golem['armed_attack_reduction'])->toBe(0)
        ->and($state->golem['active_attack_reduction'])->toBe(0);

    // The actual production crash: equipping over an existing Relic on
    // an old-shaped board used to throw a fatal TypeError spreading a
    // null relics_face_up.
    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'equip_relic', 'relic_key' => 'artists-insight', 'slot' => 'mind',
    ]);

    expect($state->wanderers['wanderer_a']['equipped']['mind'])->toBe('artists-insight')
        ->and($state->board['0,0']['relics_face_up'])->toBe(['creative-alterations']);
});

test('normalize() backfills every field it currently promises to, even when all of them are missing at once', function () {
    // Mirrors the CYTGGM regression test above, but exhaustively for
    // every field normalize() currently lists — not just the ones
    // that happened to be missing in that one incident. Strips every
    // one of them simultaneously from an old-shaped board/wanderers/
    // golem blob and confirms every single default still lands. This
    // is deliberately a hardcoded list matching normalize()'s own
    // current backfills (not auto-derived from GameSetup's output —
    // see the discipline note on normalize() itself): the point is to
    // pin down *today's* contract so any accidental removal of a
    // backfill line fails loudly, not to guess at future ones.
    $game = Game::factory()->create();
    $game->update([
        'round' => 1,
        'phase' => 'wanderer',
        'state' => [
            'wanderer_count' => 2,
            'round' => 1,
            'phase' => 'wanderer',
            'board' => [
                '0,0' => ['location_key' => 'artists-hovel', 'placed_round' => 1, 'relics' => []],
            ],
            'location_deck' => [],
            'location_hand' => [],
            'location_discard' => [],
            'relic_decks' => [],
            'wanderers' => [
                'wanderer_a' => [
                    'cell' => [0, 0], 'hp' => 15, 'actions_remaining' => 3,
                    'equipped' => ['body' => null, 'mind' => null, 'hand_left' => null, 'hand_right' => null, 'bag' => null],
                ],
                'wanderer_b' => [
                    'cell' => [0, 0], 'hp' => 15, 'actions_remaining' => 3,
                    'equipped' => ['body' => null, 'mind' => null, 'hand_left' => null, 'hand_right' => null, 'bag' => null],
                ],
            ],
            'golem' => [
                'cell' => [0, 0], 'shame' => 0, 'glyphs' => [],
                'draw_pile' => [], 'revealed' => [], 'discard_pile' => [],
            ],
            'infrastructure_pool' => ['monolith_shard' => 12, 'road' => 10, 'walls' => 2, 'fruit_tree' => 3],
            'wanderer_order' => ['wanderer_a', 'wanderer_b'],
            'active_wanderer_index' => 0,
        ],
    ]);

    $state = GameStateRepository::load($game->fresh());

    expect($state->board['0,0']['relics_face_up'])->toBe([])
        ->and($state->board['0,0']['tucked'])->toBe([])
        ->and($state->board['0,0']['infrastructure'])->toBe([])
        ->and($state->wanderers['wanderer_a']['move_blocked_this_turn'])->toBeFalse()
        ->and($state->wanderers['wanderer_a']['tucked_cards'])->toBe([])
        ->and($state->wanderers['wanderer_a']['was_ever_exhausted'])->toBeFalse()
        ->and($state->wanderers['wanderer_a']['golem_insight_used_this_round'])->toBeFalse()
        ->and($state->wanderers['wanderer_a']['laborers_jacket_used_this_round'])->toBeFalse()
        ->and($state->wanderers['wanderer_a']['pending_bonus_actions'])->toBe(0)
        ->and($state->wanderers['wanderer_a']['holding'])->toBe([])
        ->and($state->wanderers['wanderer_a']['equipped']['mind_2'])->toBeNull()
        ->and($state->wanderers['wanderer_a']['equipped']['mind_3'])->toBeNull()
        ->and($state->golem['armed_attack_reduction'])->toBe(0)
        ->and($state->golem['active_attack_reduction'])->toBe(0);
});
