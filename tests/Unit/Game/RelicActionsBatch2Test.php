<?php

use App\Game\GameEngine;
use App\Game\InvalidMoveException;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

test('Golem Prod moves the Golem 1 space in the chosen direction while sharing its space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'golem-prod'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'golem-prod', 'direction' => 'NE',
    ]);

    expect($state->golem['cell'])->toBe([2, 0])
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('golem-prod');
});

test('Golem Prod is rejected outright off the Golem\'s space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'golem-prod'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 0]])]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'golem-prod', 'direction' => 'NE',
    ]))->toThrow(InvalidMoveException::class);

    expect($state->golem['cell'])->toBe([1, 0]);
});

test('Golem intuition reveals the top 3 Golem draw pile cards without changing them', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'golem-intuition'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $expectedTop3 = array_map(fn ($c) => $c['key'], array_slice($state->golem['draw_pile'], 0, 3));

    [$newState, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-intuition']);

    $revealed = collect($events)->firstWhere('type', 'golem_draw_pile_revealed');

    expect($revealed['payload']['card_keys'])->toBe($expectedTop3)
        ->and($newState->golem['draw_pile'])->toBe($state->golem['draw_pile']);
});

test('Golem anthropology searches 3 times while on the Golem\'s space, 1 otherwise', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'golem-anthropology'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'relics' => ['burial-robes', 'last-rites', 'golem-whistle', 'quantum-robes']];
    $state = $state->with(['board' => $board]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'golem-anthropology']);

    expect($state->wanderers['wanderer_a']['holding'])->toHaveCount(3)
        ->and($state->board['1,0']['relics'])->toHaveCount(1);
});

test('Pointed finger adds Shame to the Golem and the caster, then trashes itself', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'pointed-finger'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $shameBefore = $state->golem['shame'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'pointed-finger']);

    expect($state->golem['shame'])->toBe($shameBefore + 1)
        ->and($state->wanderers['wanderer_a']['equipped'])->toContain('shame_token')
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Divine aura heals everyone on/adjacent to a Monolith Shard space by 3', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'divine-aura'];
    $wanderers = $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10]);
    $wanderers = [...$wanderers, 'wanderer_b' => [...$wanderers['wanderer_b'], 'hp' => 10]];
    $state = $state->with(['wanderers' => $wanderers]);
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-aura']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(13)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(13);
});

test('Divine aura is rejected outright off a Monolith Shard space', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'divine-aura'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-aura']))
        ->toThrow(InvalidMoveException::class);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10);
});

test('Emperor\'s Staff creates a Monolith Shard on the caster\'s space from the Infrastructure pool', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'emperors-staff'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $poolBefore = $state->infrastructurePool['monolith_shard'];

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'emperors-staff']);

    expect($state->board['1,0']['infrastructure'])->toBe(['monolith_shard'])
        ->and($state->infrastructurePool['monolith_shard'])->toBe($poolBefore - 1);
});

test('Emperor\'s Staff does nothing if the space already has a Monolith Shard', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'emperors-staff'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $poolBefore = $state->infrastructurePool['monolith_shard'];

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'emperors-staff']);

    expect($state->board['1,0']['infrastructure'])->toBe(['monolith_shard'])
        ->and($state->infrastructurePool['monolith_shard'])->toBe($poolBefore);
});

test('Ban of dangerous ideas Shames every Wanderer and Golem on a matching-branch space, then trashes itself', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'location_key' => 'cathedral']; // produces 📖
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'ban-of-dangerous-ideas'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $golemShameBefore = $state->golem['shame'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'ban-of-dangerous-ideas']);

    expect($state->wanderers['wanderer_a']['equipped'])->toContain('shame_token')
        ->and($state->wanderers['wanderer_b']['equipped'])->toContain('shame_token')
        ->and($state->golem['shame'])->toBe($golemShameBefore + 1)
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBeNull()
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Writ of solitude Shames every Wanderer sharing a space with another Wanderer, and the Golem if it shares one too', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'writ-of-solitude'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $golemShameBefore = $state->golem['shame'];

    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'writ-of-solitude']);

    expect($state->wanderers['wanderer_a']['equipped'])->toContain('shame_token')
        ->and($state->wanderers['wanderer_b']['equipped'])->toContain('shame_token')
        ->and($state->golem['shame'])->toBe($golemShameBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('relic_trashed');
});

test('Teleportation stone moves an Ally onto a Monolith Shard space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['0,0'] = [...$board['0,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'teleportation-stone'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'teleportation-stone', 'target_role' => 'wanderer_b', 'cell' => [0, 0],
    ]);

    expect($state->wanderers['wanderer_b']['cell'])->toBe([0, 0]);
});

test('Teleportation stone does nothing if the destination has no Monolith Shard', function () {
    $state = startedGame();
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'bag' => 'teleportation-stone'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'teleportation-stone', 'target_role' => 'wanderer_b', 'cell' => [0, 0],
    ]);

    expect($state->wanderers['wanderer_b']['cell'])->toBe([1, 0]);
});

test('Hands of deliverance moves a face-up Relic from one space to another', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'relics_face_up' => ['burial-robes']];
    $board['0,0'] = [...$board['0,0'], 'relics_face_up' => []];
    $state = $state->with(['board' => $board]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'hands-of-deliverance'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', [
        'type' => 'use_relic', 'relic_key' => 'hands-of-deliverance',
        'from_cell' => [1, 0], 'to_cell' => [0, 0], 'board_relic_key' => 'burial-robes',
    ]);

    expect($state->board['1,0']['relics_face_up'])->toBe([])
        ->and($state->board['0,0']['relics_face_up'])->toBe(['burial-robes'])
        ->and($state->wanderers['wanderer_a']['equipped']['hand_left'])->toBe('hands-of-deliverance');
});
