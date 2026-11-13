<?php

use App\Game\GameEngine;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Immolation rags trashes every equipped Relic (including itself) and costs 1 Health per Relic trashed', function () {
    $state = startedGame();
    $equipped = [
        'body' => 'immolation-rags', 'mind' => 'golem-whistle', 'hand_left' => 'burial-robes',
        'hand_right' => null, 'bag' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 15])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'immolation-rags']);

    expect($state->wanderers['wanderer_a']['equipped'])->toBe(['body' => null, 'mind' => null, 'hand_left' => null, 'hand_right' => null, 'bag' => null])
        ->and($state->wanderers['wanderer_a']['hp'])->toBe(12) // 3 Relics trashed => -3
        ->and(collect($events)->where('type', 'relic_trashed'))->toHaveCount(3);
});

test('Immolation rags does not touch status tokens, only Relics', function () {
    $state = startedGame();
    $equipped = [
        'body' => 'immolation-rags', 'mind' => 'shame_token', 'hand_left' => null,
        'hand_right' => null, 'bag' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 15])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'immolation-rags']);

    expect($state->wanderers['wanderer_a']['equipped']['mind'])->toBe('shame_token')
        ->and($state->wanderers['wanderer_a']['hp'])->toBe(14); // only 1 real Relic (itself) trashed
});

test('Divine Cabalist pyre burns chosen board Relics and heals everyone sharing the space 2 per Relic burned', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'relics' => ['burial-robes', 'last-rites', 'golem-whistle']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'divine-cabalist-pyre'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5]);
    $wanderers['wanderer_b']['hp'] = 5;
    $state = $state->with(['wanderers' => $wanderers]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'divine-cabalist-pyre',
        'board_relic_keys' => ['burial-robes', 'last-rites'],
    ]);

    expect($state->board['1,0']['relics'])->toBe(['golem-whistle'])
        ->and($state->wanderers['wanderer_a']['hp'])->toBe(9) // 2 burned => +4
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(9)
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Divine Cabalist pyre still trashes itself even if nothing is burned', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'divine-cabalist-pyre'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'divine-cabalist-pyre', 'board_relic_keys' => [],
    ]);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5)
        ->and($state->wanderers['wanderer_a']['equipped']['bag'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Show solidarity always trashes itself, counting toward every 5 Relics trashed for a Golem discard removal', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'show-solidarity'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $bEquipped = [
        'body' => 'golem-whistle', 'mind' => 'golem-prod', 'hand_left' => 'golem-intuition', 'hand_right' => 'golem-disguise', 'bag' => null,
    ];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $bEquipped])]);
    $golem = $state->golem;
    $golem['discard_pile'] = ['storm', 'spew'];
    $state = $state->with(['golem' => $golem]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'show-solidarity',
        'trashed' => [
            ['role' => 'wanderer_b', 'relic_key' => 'golem-whistle'],
            ['role' => 'wanderer_b', 'relic_key' => 'golem-prod'],
            ['role' => 'wanderer_b', 'relic_key' => 'golem-intuition'],
            ['role' => 'wanderer_b', 'relic_key' => 'golem-disguise'],
        ],
    ]);

    // 1 (itself) + 4 voluntary = 5 => exactly 1 Golem discard card removed.
    // 5 relic_trashed events total: 4 from the voluntary trashes plus 1
    // for Show solidarity itself (appended by useRelic's normal
    // post-dispatch trash flow, since relicShowSolidarity returns
    // trash=true rather than handling its own trash like Immolation rags).
    expect($state->golem['discard_pile'])->toHaveCount(1)
        ->and($state->wanderers['wanderer_b']['equipped'])->toBe(['body' => null, 'mind' => null, 'hand_left' => null, 'hand_right' => null, 'bag' => null])
        ->and(collect($events)->where('type', 'relic_trashed'))->toHaveCount(5);
});

test('Show solidarity ignores a requested trash for a Wanderer not sharing the Golem\'s space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'show-solidarity'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $bEquipped = [...$state->wanderers['wanderer_b']['equipped'], 'body' => 'golem-whistle'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['equipped' => $bEquipped, 'cell' => [0, 0]])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'show-solidarity',
        'trashed' => [['role' => 'wanderer_b', 'relic_key' => 'golem-whistle']],
    ]);

    expect($state->wanderers['wanderer_b']['equipped']['body'])->toBe('golem-whistle');
});

test('Show solidarity does nothing off the Golem\'s space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'show-solidarity'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 0]])]);

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'show-solidarity']);

    expect($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('show-solidarity')
        ->and(collect($events)->pluck('type'))->not->toContain('relic_trashed');
});
