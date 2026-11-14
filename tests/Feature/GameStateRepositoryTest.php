<?php

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
