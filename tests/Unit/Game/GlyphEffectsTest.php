<?php

use App\Game\Board;
use App\Game\GameEngine;
use App\Game\InvalidMoveException;

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

test('Mobile adds +1 to the Golem\'s step budget when it moves toward a target', function () {
    $state = startedGame();
    $board = $state->board;
    $board['3,0'] = ['location_key' => 'cathedral', 'placed_round' => 2, 'relics' => [], 'infrastructure' => []];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['mobile'], 'cell' => [1, 0]])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemMoveTowardCell');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, [3, 0], 1);

    expect($state->golem['cell'])->toBe([3, 0]); // 1 base + 1 Mobile = 2 steps, reaching the target
});

test('Chained subtracts 3 from the Golem\'s step budget, floored at 0', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'infrastructure' => []];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['chained'], 'cell' => [1, 0]])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemMoveTowardCell');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, [2, 0], 1);

    expect($state->golem['cell'])->toBe([1, 0]); // 1 base - 3 Chained, floored at 0 — no movement
});

test('Buoyant grants a Wanderer +2 actions on every move', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['buoyant']])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $actionsBefore = $state->wanderers['wanderer_a']['actions_remaining'];

    $direction = Board::directionBetween([1, 0], [0, 1]);
    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $direction]);

    // -1 to move, +2 from Buoyant.
    expect($state->wanderers['wanderer_a']['actions_remaining'])->toBe($actionsBefore + 1);
});

test('Empathetic reduces the Golem\'s cards played this round by Wanderers on its space', function () {
    $state = startedGame(); // both wanderers + Golem share the hub by default
    $state = $state->with(['round' => 1]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['empathetic']])]);

    expect($state->golemCardsThisRound())->toBe(1); // base 3 - 2 Wanderers present
});

test('Focused redirects nearest/farthest/least-HP targeting to the highest-HP Wanderer', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['hp' => 15])]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['focused']])]);

    $nearest = new ReflectionMethod(GameEngine::class, 'nearestWanderer');
    $nearest->setAccessible(true);
    $leastHp = new ReflectionMethod(GameEngine::class, 'leastHpWanderer');
    $leastHp->setAccessible(true);

    expect($nearest->invoke(null, $state))->toBe('wanderer_b') // highest HP, not nearest
        ->and($leastHp->invoke(null, $state))->toBe('wanderer_b'); // highest HP, not least
});

test('Barricading blocks a Wanderer from moving onto the Golem\'s space', function () {
    $state = startedGame(); // Golem starts on the hub [1,0]
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 1]])]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['barricading']])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    $direction = Board::directionBetween([0, 1], [1, 0]);

    expect(fn () => GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $direction]))
        ->toThrow(InvalidMoveException::class, 'Barricading');
});

test('Destructive destroys Infrastructure the Golem moves onto, returning it to the pool', function () {
    $state = startedGame();
    $board = $state->board;
    $board['2,0'] = [...$board['2,0'], 'infrastructure' => ['road', 'monolith_shard']];
    $state = $state->with(['board' => $board]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['destructive'], 'cell' => [1, 0]])]);
    $roadPoolBefore = $state->infrastructurePool['road'];
    $shardPoolBefore = $state->infrastructurePool['monolith_shard'];

    $reflection = new ReflectionMethod(GameEngine::class, 'golemMoveTowardCell');
    $reflection->setAccessible(true);
    [$state, $events] = $reflection->invoke(null, $state, [2, 0], 1);

    expect($state->golem['cell'])->toBe([2, 0])
        ->and($state->board['2,0']['infrastructure'])->toBe([])
        ->and($state->infrastructurePool['road'])->toBe($roadPoolBefore + 1)
        ->and($state->infrastructurePool['monolith_shard'])->toBe($shardPoolBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('infrastructure_removed');
});

test('Reactive makes the Golem play an extra card when a Wanderer moves onto its space', function () {
    $state = startedGame(); // Golem on the hub [1,0]
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['cell' => [0, 1]])]);
    // Force a plain, single-resolution card to the top of revealed
    // (array_pop takes from the end) so this stays deterministic —
    // the real deck could otherwise shuffle Double Down/Time Paradox
    // there, resolving more than 1 extra card.
    $revealed = $state->golem['revealed'];
    $revealed[array_key_last($revealed)] = ['key' => 'charge', 'tapped' => false];
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['reactive'], 'revealed' => $revealed])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);
    $discardedBefore = count($state->golem['discard_pile']);

    $direction = Board::directionBetween([0, 1], [1, 0]);
    [$state, $events] = GameEngine::apply($state, 'wanderer_a', ['type' => 'move_wanderer', 'direction' => $direction]);

    expect(count($state->golem['discard_pile']))->toBe($discardedBefore + 1)
        ->and(collect($events)->pluck('type'))->toContain('golem_card_resolved');
});

test('Fearful moves the Golem 1 space away from the nearest Wanderer when a tapped card resolves', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['fearful'], 'cell' => [1, 0]])]);
    // wanderer_a stays on the hub [1,0]; the Golem should step away from it.

    $reflection = new ReflectionMethod(GameEngine::class, 'resolveOrSkipTappedCard');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, ['key' => 'charge', 'tapped' => true]);

    expect($state->golem['cell'])->not->toBe([1, 0]);
});

test('Adaptive sends a card that dealt damage to the bottom of the draw pile instead of the discard pile', function () {
    $state = startedGame(); // both wanderers + Golem share the hub
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['adaptive'], 'revealed' => [
        ['key' => 'pound', 'tapped' => false],
    ]])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 15])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['hp' => 15])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemPlayCards');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 1);

    expect($state->golem['discard_pile'])->not->toContain('pound')
        ->and(collect($state->golem['draw_pile'])->pluck('key'))->toContain('pound');
});

test('Accusing gives a Wanderer 1 Shame when a Golem attack actually costs them Health', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['accusing']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 3, true);

    expect($state->wanderers['wanderer_a']['equipped'])->toContain('shame_token');
});

test('Accusing does not trigger if the attack dealt 0 Health loss', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['accusing', 'merciful']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 10])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 1, true); // Merciful reduces this to 0

    expect($state->wanderers['wanderer_a']['equipped'])->not->toContain('shame_token');
});

test('Vindictive makes the Golem attack everyone on its space for 5 when a 🌿 Relic is played', function () {
    $state = startedGame(); // both wanderers + Golem share the hub
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['vindictive']])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'divine-healing-oil'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['hp' => 15])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-healing-oil']);

    // +3 self-heal from Divine healing oil (5 -> 8), then -5 from Vindictive = 3.
    expect($state->wanderers['wanderer_a']['hp'])->toBe(3)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(10); // 15 - 5
});

test('Blessed adds +2 to any Health gained from a Relic use', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['blessed']])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'divine-healing-oil'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-healing-oil']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(10); // 5 + 3 (oil) + 2 (Blessed)
});

test('Congenial adds +3 to any Health gained from a Relic used while sharing a space with another Wanderer', function () {
    $state = startedGame(); // both wanderers share the hub by default
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['congenial']])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'divine-healing-oil'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-healing-oil']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(11); // 5 + 3 (oil) + 3 (Congenial)
});

test('Congenial does not apply when the caster is alone on their space', function () {
    $state = startedGame();
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['cell' => [0, 1]])]);
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['congenial']])]);
    $equipped = [...$state->wanderers['wanderer_a']['equipped'], 'hand_left' => 'divine-healing-oil'];
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['equipped' => $equipped, 'hp' => 5])]);
    [$state] = GameEngine::apply($state, 'emperor', ['type' => 'end_emperor_phase', 'discard' => []]);

    [$state] = GameEngine::apply($state, 'wanderer_a', ['type' => 'use_relic', 'relic_key' => 'divine-healing-oil']);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(8); // 5 + 3 (oil) only
});

test('Forgiving heals every Wanderer 1 each time the Golem plays a card', function () {
    $state = startedGame();
    // Force a non-damaging card to the top of revealed (array_pop takes
    // from the end) so this test is isolated from the Golem's real,
    // randomly-shuffled deck possibly dealing damage of its own.
    $revealed = $state->golem['revealed'];
    $revealed[array_key_last($revealed)] = ['key' => 'self-inscription', 'tapped' => false];
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['forgiving'], 'revealed' => $revealed])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_b', ['hp' => 5])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'golemPlayCards');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 1);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(6)
        ->and($state->wanderers['wanderer_b']['hp'])->toBe(6);
});

test('Miraculous heals a Wanderer to 3 the moment they become exhausted', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['miraculous']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 5])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 10, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(3);
});

test('Miraculous does not re-trigger on further damage to an already-exhausted Wanderer', function () {
    $state = startedGame();
    $state = $state->with(['golem' => $state->golemWith(['glyphs' => ['miraculous']])]);
    $state = $state->with(['wanderers' => $state->wanderersWith('wanderer_a', ['hp' => 0])]);

    $reflection = new ReflectionMethod(GameEngine::class, 'applyDamage');
    $reflection->setAccessible(true);
    [$state] = $reflection->invoke(null, $state, 'wanderer_a', 5, true);

    expect($state->wanderers['wanderer_a']['hp'])->toBe(0);
});
