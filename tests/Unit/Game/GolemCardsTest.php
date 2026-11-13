<?php

use App\Game\Board;

// startedGame() (GameEngineTest.php) and resolveGolemCardForTest()
// (StatusTokensTest.php) are declared in sibling files, loaded in the
// same run. startedGame() puts both Wanderers and the Golem on the hub
// cell [1, 0], which is adjacent to all 5 other starting cells.

test('Deface trashes the top face-down Relic from the Golem\'s space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'relics' => ['burial-robes', 'last-rites']];
    $state = $state->with(['board' => $board]);

    [$state, $events] = resolveGolemCardForTest($state, 'deface');

    expect($state->board['1,0']['relics'])->toBe(['last-rites'])
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed_from_board');
});

test('Deface does nothing if the Golem\'s space has no face-down Relics', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'relics' => []];
    $state = $state->with(['board' => $board]);

    [$state, $events] = resolveGolemCardForTest($state, 'deface');

    expect($state->board['1,0']['relics'])->toBe([])
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed_from_board');
});

test('Fire storm damages everyone on/adjacent to the Golem and trashes a Relic from anyone who was hurt', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burial-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    [$state, $events] = resolveGolemCardForTest($state, 'fire-storm');

    expect($state->wanderers['wanderer_a']['hp'])->toBe(12)
        ->and($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(12)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Great Sweeping Scythe damages everyone on/adjacent to the Golem and Scars anyone who was hurt', function () {
    $state = startedGame();

    [$state, $events] = resolveGolemCardForTest($state, 'great-sweeping-scythe');

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and($state->wanderers['wanderer_a']['equipped'])->toContain('scar_token')
        ->and($state->wanderers['wanderer_b']['equipped'])->toContain('scar_token')
        ->and(collect($events)->pluck('type'))->toContain('wanderer_damaged');
});

test('Overcharge damages a Wanderer 1 per Monolith Shard on or adjacent to their space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $board['0,0'] = [...$board['0,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);

    [$state] = resolveGolemCardForTest($state, 'overcharge');

    // wanderer_a/b sit on [1,0], which has 1 Shard on it and 1 more on
    // the adjacent [0,0] — 2 total.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(13)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(13);
});

test('Overcharge does nothing to a Wanderer not on a Monolith Shard space', function () {
    $state = startedGame();

    [$state] = resolveGolemCardForTest($state, 'overcharge');

    expect($state->wanderers['wanderer_a']['hp'])->toBe(15);
});

test('Quake moves the Golem toward the nearest Road, damages every Road space, and returns that space\'s Infrastructure', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'infrastructure' => ['road', 'fruit_tree']];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 0]])]);

    $roadPoolBefore = $state->infrastructurePool['road'];
    $fruitTreePoolBefore = $state->infrastructurePool['fruit_tree'];

    [$state, $events] = resolveGolemCardForTest($state, 'quake');

    expect($state->golem['cell'])->toBe([0, 0])
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(13) // was on the Road space
        ->and($state->board['0,0']['infrastructure'])->toBe([])
        ->and($state->infrastructurePool['road'])->toBe($roadPoolBefore + 1)
        ->and($state->infrastructurePool['fruit_tree'])->toBe($fruitTreePoolBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('infrastructure_returned');
});

test('Roar damages everyone on/adjacent to the Golem and forces them 1 space away', function () {
    $state = startedGame();

    [$state, $events] = resolveGolemCardForTest($state, 'roar');

    expect($state->wanderers['wanderer_a']['hp'])->toBe(12)
        ->and($state->wanderers['wanderer_a']['cell'])->not->toBe([1, 0])
        ->and(array_key_exists(Board::key($state->wanderers['wanderer_a']['cell']), $state->board))->toBeTrue()
        ->and(collect($events)->pluck('type'))->toContain('wanderer_moved');
});

test('Sacrifice of Possessions trashes one equipped Relic from every Wanderer', function () {
    $state = startedGame();
    $wanderers = $state->wanderersWith('wanderer_a', [
        'equipped' => [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'burial-robes'],
    ]);
    $wanderers['wanderer_b']['equipped']['mind'] = 'golem-whistle';
    $state = $state->with(['wanderers' => $wanderers]);

    [$state] = resolveGolemCardForTest($state, 'sacrifice-of-possessions');

    expect($state->wanderers['wanderer_a']['equipped']['body'])->toBeNull()
        ->and($state->wanderers['wanderer_b']['equipped']['mind'])->toBeNull();
});

test('Shard Devourer moves toward the nearest Monolith Shard, removes it, and damages around it', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 0]])]);

    [$state, $events] = resolveGolemCardForTest($state, 'shard-devourer');

    expect($state->golem['cell'])->toBe([0, 0])
        ->and($state->board['0,0']['infrastructure'])->toBe([])
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(13)
        ->and(collect($events)->pluck('type'))->toContain('infrastructure_removed');
});

test('Siege moves adjacent to the nearest Wall and damages Wanderers adjacent to it', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'infrastructure' => ['walls']];
    $state = $state->with(['board' => $board]);

    // The Golem starts on [1,0], already adjacent to the Wall at [0,0].
    [$state, $events] = resolveGolemCardForTest($state, 'siege');

    expect($state->golem['cell'])->toBe([1, 0]) // no move needed
        ->and($state->wanderers['wanderer_a']['hp'])->toBe(11)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(11)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_damaged');
});

test('Summoning Roar pulls every Wanderer 2 spaces toward the Golem, then damages whoever shares its space', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 0]])]);

    [$state, $events] = resolveGolemCardForTest($state, 'summoning-roar');

    expect($state->wanderers['wanderer_a']['cell'])->toBe([1, 0])
        ->and($state->wanderers['wanderer_a']['hp'])->toBe(12)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(12)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_moved');
});

test('Targeted attack moves toward the nearest Wanderer and hits them and their Ally for 5', function () {
    $state = startedGame();

    [$state, $events] = resolveGolemCardForTest($state, 'targeted-attack');

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(10)
        ->and(collect($events)->pluck('type'))->toContain('wanderer_damaged');
});

test('Targeted attack does not damage a lone target with no Ally nearby', function () {
    $state = startedGame();
    // [0,0] and [2,0] are each only adjacent to the hub [1,0], not to
    // each other — placing the Wanderers there (distance 1 from the
    // Golem, tied) leaves wanderer_a (first in iteration order) as the
    // nearest target, with no Ally within 1 space of its own cell.
    $wanderers = $state->wanderersWith('wanderer_a', ['cell' => [0, 0]]);
    $wanderers['wanderer_b']['cell'] = [2, 0];
    $state = $state->with(['wanderers' => $wanderers]);

    [$state] = resolveGolemCardForTest($state, 'targeted-attack');

    $totalDamageTaken = (15 - $state->wanderers['wanderer_a']['hp']) + (15 - $state->wanderers['wanderer_b']['hp']);

    expect($state->golem['cell'])->toBe([0, 0])
        ->and($totalDamageTaken)->toBe(0);
});
