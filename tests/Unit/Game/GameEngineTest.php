<?php

use App\Game\Board;
use App\Game\Catalog\LocationCatalog;
use App\Game\Catalog\RelicCatalog;
use App\Game\GameEngine;
use App\Game\GameSetup;
use App\Game\GameState;
use App\Game\InvalidMoveException;
use App\Game\RoleKeys;

/**
 * Starts a fresh 2-Wanderer game, places both Wanderers and the Golem on
 * the starting hub cell [1,0] (adjacent to every other starting cell), and
 * returns the resulting state. Shared setup for most tests below.
 */
function startedGame(): GameState
{
    $state = GameSetup::create(2);

    // The 6 starting Locations are dealt randomly — swap out any whose
    // own mechanic would make an unrelated test nondeterministic for a
    // neutral stand-in. Tests that specifically want one of these set
    // board[...]['location_key'] explicitly anyway.
    //   - Catacombs blocks entry outright.
    //   - Beds of poison deals surprise damage on entry.
    //   - Golem sanctuary silently tucks (instead of trashing) any
    //     healing Relic used there — and the Golem and both Wanderers
    //     start on the same hub cell by default, so this would hijack
    //     any other heal-Relic test that happens to land on the hub.
    //   - House of Cryptids tucks a Golem card every time the Wanderer
    //     phase ends while all Wanderers share the Golem's space —
    //     true by default at the hub, so this would silently consume
    //     draw_pile cards in any test ending a turn there.
    $board = $state->board;
    foreach ($board as $key => $entry) {
        if (in_array($entry['location_key'], ['catacombs', 'beds-of-poison', 'golem-sanctuary', 'house-of-cryptids'], true)) {
            $board[$key] = [...$entry, 'location_key' => 'artists-hovel'];
        }
    }
    $state = $state->with(['board' => $board]);

    foreach (RoleKeys::wanderers(2) as $role) {
        [$state] = GameEngine::apply($state, $role, ['type' => 'choose_starting_cell', 'cell' => [1, 0]]);
    }

    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'choose_golem_cell', 'cell' => [1, 0]]);

    return $state;
}

test('setup deals a 5-card Emperor hand and places exactly 6 starting Locations', function () {
    $state = GameSetup::create(2);

    expect($state->locationHand)->toHaveCount(5)
        ->and($state->board)->toHaveCount(6)
        ->and($state->phase)->toBe('setup')
        ->and($state->round)->toBe(1);
});

test('setup finishes only once every Wanderer and the Golem have a cell', function () {
    $state = GameSetup::create(2);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'choose_starting_cell', 'cell' => [1, 0]]);
    expect($state->phase)->toBe('setup');

    [$state, $events] = GameEngine::apply($state, 'wanderer_b', ['type' => 'choose_starting_cell', 'cell' => [1, 0]]);
    expect($state->phase)->toBe('setup')
        ->and($state->pendingGolemPlacement)->toBeTrue();

    [$state, $events] = GameEngine::apply($state, 'emperor', ['type' => 'choose_golem_cell', 'cell' => [1, 0]]);
    expect($state->phase)->toBe('emperor')
        ->and(collect($events)->pluck('type'))->toContain('round_started');
});

test('a Wanderer cannot start on an unbuilt cell', function () {
    $state = GameSetup::create(2);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'choose_starting_cell', 'cell' => [99, 99]]);
})->throws(InvalidMoveException::class);

test('playing a Location requires it to be in the Emperor hand', function () {
    $state = startedGame();

    GameEngine::apply($state, 'emperor', [
        'type' => 'play_location', 'location_key' => 'not-a-real-location', 'cell' => [1, -1],
    ]);
})->throws(InvalidMoveException::class, 'That Location is not in your hand.');

test('playing a Location onto an already-built space fails', function () {
    $state = startedGame();
    $locationKey = $state->locationHand[0];

    GameEngine::apply($state, 'emperor', ['type' => 'play_location', 'location_key' => $locationKey, 'cell' => [1, 0]]);
})->throws(InvalidMoveException::class, 'That space is already built.');

test('playing a Location enforces its adjacency resource cost', function () {
    $state = startedGame();

    // Find a hand Location with at least one cost, and verify it's rejected
    // next to a neighbor that doesn't produce that resource (if no such
    // Location is in hand this run, the test trivially passes nothing —
    // guard with a real assertion by searching the full catalog instead).
    $costed = collect(LocationCatalog::all())->first(fn ($l) => $l->costs !== []);
    expect($costed)->not->toBeNull('Expected at least one Location with a cost in the catalog.');

    // Force it into the hand so the test is deterministic regardless of shuffle.
    $state = $state->with(['locationHand' => [...$state->locationHand, $costed->key]]);

    $placeable = Board::placeableCells($state->board);
    $badCell = collect($placeable)->first(function ($cell) use ($state, $costed) {
        foreach ($costed->costs as $cost) {
            foreach (Board::occupiedNeighbors($cell, $state->board) as $neighbor) {
                $entry = $state->board[Board::key($neighbor)];
                if (LocationCatalog::find($entry['location_key'])->produces === $cost) {
                    return false;
                }
            }
        }

        return true;
    });

    expect($badCell)->not->toBeNull();

    GameEngine::apply($state, 'emperor', ['type' => 'play_location', 'location_key' => $costed->key, 'cell' => $badCell]);
})->throws(InvalidMoveException::class);

test('a successfully played Location deals its Relic pile and leaves the Emperor hand', function () {
    $state = startedGame();
    // Bazaar has its own dynamic dealing (tested separately) and would
    // make this assertion flaky, since its printed relicCount (parsed
    // from "1 of each type...") doesn't reflect what it actually deals.
    $costFree = collect(LocationCatalog::all())->first(fn ($l) => $l->costs === [] && $l->key !== 'bazaar');
    $state = $state->with(['locationHand' => [$costFree->key]]);

    $cell = Board::placeableCells($state->board)[0];

    [$state, $events] = GameEngine::apply($state, 'emperor', [
        'type' => 'play_location', 'location_key' => $costFree->key, 'cell' => $cell,
    ]);

    $cellKey = Board::key($cell);
    expect($state->board[$cellKey]['location_key'])->toBe($costFree->key)
        ->and($state->board[$cellKey]['relics'])->toHaveCount($costFree->relicCount)
        ->and($state->locationHand)->not->toContain($costFree->key)
        ->and($events[0]['type'])->toBe('location_played');
});

test('Bazaar deals 1 Relic per distinct branch already produced by the city, not its printed count', function () {
    $state = startedGame();
    $state = $state->with(['locationHand' => ['bazaar']]);

    $producedBranches = collect($state->board)
        ->map(fn ($entry) => LocationCatalog::find($entry['location_key'])->producesEmoji)
        ->filter()
        ->unique();

    $cell = Board::placeableCells($state->board)[0];
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'play_location', 'location_key' => 'bazaar', 'cell' => $cell]);

    $dealt = $state->board[Board::key($cell)]['relics'];

    expect(count($dealt))->toBeLessThanOrEqual($producedBranches->count());
    foreach ($dealt as $relicKey) {
        expect(RelicCatalog::find($relicKey)->symbol)->toBeIn($producedBranches->all());
    }
});

test('only the Emperor may play a Location, and only during the emperor phase', function () {
    $state = startedGame();
    $key = $state->locationHand[0];
    $cell = Board::placeableCells($state->board)[0];

    GameEngine::apply($state, 'wanderer_a', ['type' => 'play_location', 'location_key' => $key, 'cell' => $cell]);
})->throws(InvalidMoveException::class, "It isn't your turn.");

test('ending the Emperor phase draws back up to 5 and starts the first Wanderer turn', function () {
    $state = startedGame();

    [$state, $events] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect($state->phase)->toBe('wanderer')
        ->and($state->locationHand)->toHaveCount(5)
        ->and($state->activeWandererRole())->toBe('wanderer_a')
        ->and($state->wanderers['wanderer_a']['actions_remaining'])->toBe(3)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_turn_started');
});

test('a Wanderer cannot move onto an unbuilt space', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    // [1,0] (hub) has no neighbor at [5,5]; pick a direction with no built neighbor
    $direction = collect(Board::DIRECTIONS)->first(fn ($d) => ! array_key_exists(
        Board::key(Board::neighbor([1, 0], $d)), $state->board
    ));

    GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $direction]);
})->throws(InvalidMoveException::class, 'Wanderers must always be on a Location.');

test('moving consumes one action and only the active Wanderer may move', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $direction = collect(Board::DIRECTIONS)->first(fn ($d) => array_key_exists(
        Board::key(Board::neighbor([1, 0], $d)), $state->board
    ));

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $direction]);

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(2)
        ->and($events[0]['type'])->toBe('wanderer_moved');

    GameEngine::apply($state, 'wanderer_b', ['type' => 'move_wanderer', 'direction' => $direction]);
})->throws(InvalidMoveException::class, "It isn't your turn.");

test('ending every Wanderer turn resolves the Golem phase and advances the round', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'end_wanderer_turn']);
    [$state, $events] = GameEngine::apply($state, 'wanderer_b', ['type' => 'end_wanderer_turn']);

    expect($state->round)->toBe(2)
        ->and($state->phase)->toBe('emperor')
        ->and(collect($events)->pluck('type'))->toContain('round_started');
});

test('the game finishes after round 8 with defeat (no Prophecy sources are wired up yet)', function () {
    $state = startedGame();

    for ($round = 1; $round <= 8; $round++) {
        [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
        foreach (RoleKeys::wanderers(2) as $role) {
            [$state] = GameEngine::apply($state, $role, ['type' => 'end_wanderer_turn']);
        }
    }

    expect($state->phase)->toBe('finished')
        ->and($state->round)->toBe(8)
        ->and($state->outcome())->toBe('defeat');
});

test('outcome() thresholds match the confirmed design (12 = victory, 16 = elite)', function () {
    $state = startedGame()->with(['prophecy' => 0]);
    expect($state->outcome())->toBe('defeat');

    expect($state->with(['prophecy' => 11])->outcome())->toBe('defeat');
    expect($state->with(['prophecy' => 12])->outcome())->toBe('simple_victory');
    expect($state->with(['prophecy' => 15])->outcome())->toBe('simple_victory');
    expect($state->with(['prophecy' => 16])->outcome())->toBe('elite_victory');
});

// --- Relics: search / equip / drop / pickup ---

test('searching an empty-pile space still costs an action and finds nothing', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    // Force the current cell's pile empty for a deterministic result.
    $cellKey = Board::key($state->wanderers['wanderer_a']['cell']);
    $board = $state->board;
    $board[$cellKey]['relics'] = [];
    $state = $state->with(['board' => $board]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'search_location']);

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(2)
        ->and($events[0]['payload']['found'])->toBeNull();
});

test('searching a non-empty pile moves a Relic into holding, unless it is an Event (trashed instead)', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $nonEventRelic = collect(RelicCatalog::all())->first(fn ($r) => ! $r->isEvent);
    $cellKey = Board::key($state->wanderers['wanderer_a']['cell']);
    $board = $state->board;
    $board[$cellKey]['relics'] = [$nonEventRelic->key];
    $state = $state->with(['board' => $board]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'search_location']);

    expect($state->wanderers['wanderer_a']['holding'])->toBe([$nonEventRelic->key])
        ->and($events[0]['payload']['trashed_as_event'])->toBeFalse();

    $eventRelic = collect(RelicCatalog::all())->first(fn ($r) => $r->isEvent);
    $board2 = $state->board;
    $board2[$cellKey]['relics'] = [$eventRelic->key];
    $state = $state->with(['board' => $board2, 'wanderers' => $state->wanderersWith('wanderer_a', ['holding' => []])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'search_location']);

    expect($state->wanderers['wanderer_a']['holding'])->toBe([])
        ->and($events[0]['payload']['trashed_as_event'])->toBeTrue();
});

test('equipping a held Relic into a matching slot works and rejects a mismatched slot', function () {
    $state = startedGame();
    $bodyRelic = collect(RelicCatalog::all())->first(fn ($r) => $r->slot === 'Body');
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['holding' => [$bodyRelic->key]])]);

    GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => $bodyRelic->key, 'slot' => 'mind']);
})->throws(InvalidMoveException::class, 'That Relic does not fit that slot.');

test('equipping displaces whatever was already in that slot onto the ground', function () {
    $state = startedGame();
    $bodyRelics = collect(RelicCatalog::all())->filter(fn ($r) => $r->slot === 'Body')->values();
    expect($bodyRelics->count())->toBeGreaterThanOrEqual(2);

    [$first, $second] = [$bodyRelics[0]->key, $bodyRelics[1]->key];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'holding' => [$first, $second],
        'equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'body' => null],
    ])]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => $first, 'slot' => 'body']);
    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBe($first);

    $cellKey = Board::key($state->wanderers['wanderer_a']['cell']);
    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'equip_relic', 'relic_key' => $second, 'slot' => 'body']);

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBe($second)
        ->and($state->board[$cellKey]['relics'])->toContain($first);
});

test('relic pickup/drop is not gated by whose turn it is', function () {
    $state = startedGame();
    $relic = collect(RelicCatalog::all())->first();
    $cellKey = Board::key($state->wanderers['wanderer_b']['cell']);
    $board = $state->board;
    $board[$cellKey]['relics'] = [$relic->key];
    $state = $state->with(['board' => $board]);

    // It's nobody's "turn" yet (still setup phase is over, but we're in
    // emperor phase) — pickup should still work for a Wanderer.
    [$state, $events] = GameEngine::apply($state, 'wanderer_b', ['type' => 'pickup_relic', 'relic_key' => $relic->key]);

    expect($state->wanderers['wanderer_b']['holding'])->toContain($relic->key)
        ->and($events[0]['type'])->toBe('relic_picked_up');
});

// --- Infrastructure ---

test('placing Infrastructure decrements the pool and is capped at 2 per turn', function () {
    $state = startedGame();

    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'road', 'cell' => [1, 0]]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'fruit_tree', 'cell' => [1, 0]]);

    expect($state->infrastructurePool['road'])->toBe(9)
        ->and($state->infrastructurePool['fruit_tree'])->toBe(1)
        ->and($state->infrastructurePlacedThisTurn)->toBe(2);

    GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'walls', 'cell' => [1, 0]]);
})->throws(InvalidMoveException::class, 'Already placed the maximum Infrastructure this turn.');

test('only 1 Monolith Shard may be placed per space', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'monolith_shard', 'cell' => [1, 0]]);

    GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'monolith_shard', 'cell' => [1, 0]]);
})->throws(InvalidMoveException::class, 'Only 1 Monolith Shard per space.');

test('Walls cannot be placed on the Golem\'s own space', function () {
    $state = startedGame(); // Golem is at [1,0]

    GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'walls', 'cell' => [1, 0]]);
})->throws(InvalidMoveException::class, "Cannot wall the Golem's own space.");

test('arriving on a Road makes the next move free, and it chains through consecutive Roads', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'road', 'cell' => [0, 1]]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $toRoad = Board::directionBetween([1, 0], [0, 1]);
    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $toRoad]);
    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(2)
        ->and($state->wanderers['wanderer_a']['free_move_available'])->toBeTrue();

    $backToHub = Board::directionBetween([0, 1], [1, 0]);
    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $backToHub]);

    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(2) // unchanged — free move
        ->and($events[0]['payload']['free'])->toBeTrue();
});

test('entering a Fruit tree space grants a bonus action', function () {
    $state = startedGame();
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'fruit_tree', 'cell' => [0, 1]]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $direction = Board::directionBetween([1, 0], [0, 1]);
    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $direction]);

    // started at 3, -1 for the move, +1 for the fruit tree = 3
    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe(3)
        ->and(collect($events)->pluck('type'))->toContain('fruit_tree_bonus_action');
});

test('Walls block the Golem\'s pathing', function () {
    $state = GameSetup::create(2);
    foreach (RoleKeys::wanderers(2) as $role) {
        [$state] = GameEngine::apply($state, $role, ['type' => 'choose_starting_cell', 'cell' => [0, 0]]);
    }
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'choose_golem_cell', 'cell' => [2, 0]]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'place_infrastructure', 'infrastructure_type' => 'walls', 'cell' => [1, 0]]);

    // [1,0] is the only hub connecting [2,0] to [0,0] in the starting
    // layout's direct line — walling it should sever that specific path
    // even though a longer route might still exist through [1,1]/[0,1].
    $direct = Board::distance([2, 0], [1, 0], $state->board, $state->wallKeys());
    expect($direct)->toBeNull();
});
