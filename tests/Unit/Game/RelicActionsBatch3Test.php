<?php

use App\Game\GameEngine;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Divine robes heals everyone, regardless of distance, while on a 📖 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'cathedral']; // produces 📖
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'divine-robes'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5]);
    $wanderers = [...$wanderers, 'wanderer_b' => [...$wanderers['wanderer_b'], 'hp' => 5]];
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-robes']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(10);
});

test('Divine robes does nothing off a 📖 space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'artists-hovel']; // produces 🎨
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'divine-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-robes']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
});

test('Divine solidarity heals the caster and every Ally sharing their space by 4', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'divine-solidarity'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10]);
    $wanderers = [...$wanderers, 'wanderer_b' => [...$wanderers['wanderer_b'], 'hp' => 10]];
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-solidarity']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(14)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(14);
});

test('Divine solidarity does nothing alone', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'divine-solidarity'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 0]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-solidarity']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10);
});

// Warlock's robes requires the chosen Ally to share the caster's
// space (same "Ally" definition used everywhere else, via
// resolveAllyTarget). Artist's insight (🦶2, purely self-directed —
// moves whoever's actorRole the move resolves with) cleanly reveals
// which of the two actually performed the borrowed action, since it
// doesn't care about $move['target_role'] at all.

test('Warlock\'s robes lets an Ally perform the action from one of the caster\'s other Relics', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'warlocks-robes', 'bag' => 'artists-insight'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'warlocks-robes', 'target_role' => 'wanderer_b',
        'mode' => 'ally_uses_mine', 'other_relic_key' => 'artists-insight', 'directions' => ['NE'],
    ]);

    expect($state->wanderers['wanderer_b']['cell'])->toBe([2, 0]) // the Ally performed it, moving themselves
        ->and($state->wanderers['wanderer_a']['cell'])->toBe([1, 0])
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBe('artists-insight'); // borrowed, not trashed
});

test('Warlock\'s robes lets the caster perform the action from an Ally\'s Relic', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'body' => 'warlocks-robes'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $bEquipped = [...$state->wanderers['wanderer_b']['equipped'], 'bag' => 'artists-insight'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $bEquipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'warlocks-robes', 'target_role' => 'wanderer_b',
        'mode' => 'i_use_theirs', 'other_relic_key' => 'artists-insight', 'directions' => ['NE'],
    ]);

    expect($state->wanderers['wanderer_a']['cell'])->toBe([2, 0]) // the caster performed it, moving themselves
        ->and($state->wanderers['wanderer_b']['cell'])->toBe([1, 0])
        ->and($state->wanderers['wanderer_b']['equipped']['bag'])->toBe('artists-insight'); // borrowed, not trashed
});

test('Emperor\'s Diary searches once, plus once more per 3 Emperor\'s Relics held or equipped', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'emperors-diary', 'mind' => 'emperors-knowledge'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped, 'holding' => ['emperors-staff'],
    ])]);
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'relics' => ['burial-robes', 'last-rites', 'golem-whistle']];
    $state = $state->with(['board' => $board]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'emperors-diary']);

    // 3 Emperor's Relics (equipped x2 + held x1) => 1 + floor(3/3) = 2 searches.
    $newlyHeld = array_values(array_diff($state->wanderers['wanderer_a']['holding'], ['emperors-staff']));
    expect($newlyHeld)->toHaveCount(2);
});

test('Emperor\'s Robes heals 1 per equipped Emperor\'s Relic (held ones don\'t count)', function () {
    $state = startedGame();
    $equipped = [
        ...$state->wanderers['wanderer_a']['equipped'],
        'hand_left' => 'emperors-staff', 'mind' => 'emperors-knowledge', 'body' => 'emperors-robes',
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', [
        'equipped' => $equipped, 'holding' => ['emperors-diary'], 'hp' => 10,
    ])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'emperors-robes']);

    // 3 equipped Emperor's Relics — Staff, Knowledge, and Emperor's
    // Robes itself (it counts too: it's an equipped "Emperor's __"
    // Relic same as any other) — the held Diary doesn't count. => +3.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(13);
});
