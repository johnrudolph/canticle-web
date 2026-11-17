<?php

use App\Game\GameEngine;

// startedGame() (GameEngineTest.php) is declared in a sibling file,
// loaded in the same run. It puts both Wanderers and the Golem on the
// hub cell [1, 0], adjacent to all 5 other starting cells.

function applyDamageForTest(string $role, int $damage, bool $isGolemAttack = false): callable
{
    return function ($state) use ($role, $damage, $isGolemAttack) {
        $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
        $reflection->setAccessible(true);

        return $reflection->invoke(null, $state, $role, $damage, $isGolemAttack);
    };
}

test('Cruel adds +1 to every Golem attack', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['cruel']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = (applyDamageForTest('wanderer_a', 3, true))($state);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(6); // 10 - (3+1)
});

test('Merciful subtracts 1 from every Golem attack, floored at 0', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['merciful']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = (applyDamageForTest('wanderer_a', 1, true))($state);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10); // 1 - 1 = 0 damage
});

test('Legalistic adds +1 Golem attack damage per Shame the target has', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['legalistic']])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'mind' => 'shame_token', 'hand_left' => 'shame_token'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 10])]);

    [$state] = (applyDamageForTest('wanderer_a', 2, true))($state);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(6); // 10 - (2 + 2 Shame)
});

test('Enchanted subtracts 2 from a Golem attack while the Golem is on a Monolith Shard space', function () {
    $state = startedGame();
    $board = $state->board;
    $board['1,0'] = [...$board['1,0'], 'infrastructure' => ['monolith_shard']];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['enchanted'], 'cell' => [1, 0]])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = (applyDamageForTest('wanderer_a', 5, true))($state);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(7); // 10 - (5-2)
});

test('Enchanted does nothing if the Golem is off a Monolith Shard space', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['enchanted'], 'cell' => [1, 0]])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = (applyDamageForTest('wanderer_a', 5, true))($state);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(5);
});

test('Outnumbered subtracts 3 from a Golem attack on a Wanderer sharing a space with another Wanderer', function () {
    $state = startedGame(); // both wanderers share the hub cell by default
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['outnumbered']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = (applyDamageForTest('wanderer_a', 4, true))($state);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(9); // 10 - (4-3)
});

test('Outnumbered does not apply to a Wanderer alone on their space', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 1]])]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['outnumbered']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = (applyDamageForTest('wanderer_a', 4, true))($state);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(6); // no reduction
});

test('Combat Glyphs never affect non-Golem damage', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['cruel']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    [$state] = (applyDamageForTest('wanderer_a', 3, false))($state);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(7); // plain -3, no Cruel bonus
});

test('Accused adds a virtual +1 Shame wherever the Golem\'s Shame is read as a quantity, without raising the stored value', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['accused'], 'shame' => 1])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'whip-of-justice'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'whip-of-justice']);

    $tapped = count(array_filter($state->golem['draw_pile'], fn ($c) => $c['tapped']));
    expect($tapped)->toBe(2) // 1 stored Shame + 1 virtual from Accused
        ->and($state->golem['shame'])->toBe(1); // stored value never changes
});

test('Penitent reduces the Golem\'s cards played this round by its Shame, floored at 0', function () {
    $state = startedGame();
    $state = $state->with(['round' => 1]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['penitent'], 'shame' => 2])]);

    expect($state->golemCardsThisRound())->toBe(1); // base 3 - 2 Shame
});

test('Lilted reduces the Golem\'s cards played this round by equipped 🎶 Relics', function () {
    $state = startedGame();
    $state = $state->with(['round' => 1]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['lilted']])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'lute', 'hand_right' => 'pipe'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped])]);

    expect($state->golemCardsThisRound())->toBe(1); // base 3 - 2 instruments
});

test('Empathetic reduces the Golem\'s cards played this round by Wanderers on its space', function () {
    $state = startedGame(); // both wanderers + Golem share the hub by default
    $state = $state->with(['round' => 1]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['empathetic']])]);

    expect($state->golemCardsThisRound())->toBe(1); // base 3 - 2 Wanderers present
});
