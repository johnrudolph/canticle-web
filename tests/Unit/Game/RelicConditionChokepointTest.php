<?php

use App\Game\GameEngine;

/**
 * Extracts every top-level `'key' => ...` / `'key', 'key2' => ...` match
 * arm's string literals from a private method's source, via reflection
 * + regex over its own file. Good enough for the flat, single-level
 * match statements this engine uses for relic dispatch — not a real
 * PHP parser, just a drift detector.
 *
 * @return list<string>
 */
function matchArmKeys(string $method): array
{
    $reflection = new ReflectionMethod(GameEngine::class, $method);
    $filename = $reflection->getFileName();
    $start = $reflection->getStartLine() - 1;
    $end = $reflection->getEndLine();
    $lines = array_slice(file($filename), $start, $end - $start);
    $source = implode('', $lines);

    // Every line that is itself the start of a match arm looks like
    // `'key', 'key2' => ...` at the left margin of the match body —
    // grab the quoted keys from every such line.
    preg_match_all("/^\s{3,}((?:'[^']+'\s*,\s*)*'[^']+')\s*=>/m", $source, $armLines);

    $keys = [];
    foreach ($armLines[1] as $armLine) {
        preg_match_all("/'([^']+)'/", $armLine, $quoted);
        $keys = [...$keys, ...$quoted[1]];
    }

    return $keys;
}

test('every condition checked by relicUnmetConditionReason() has a matching case in resolveRelicAction()', function () {
    $conditionKeys = matchArmKeys('relicUnmetConditionReason');
    $dispatchKeys = matchArmKeys('resolveRelicAction');

    expect($conditionKeys)->not->toBeEmpty();

    $missing = array_diff($conditionKeys, $dispatchKeys);

    expect($missing)->toBe([], 'relicUnmetConditionReason() checks a condition with no matching case in resolveRelicAction() — likely stale after a rename: '.implode(', ', $missing));
});

test('every condition checked by inscribeUnmetReason() has a matching case in resolveInscribe()', function () {
    $conditionKeys = matchArmKeys('inscribeUnmetReason');
    $dispatchKeys = matchArmKeys('resolveInscribe');

    expect($conditionKeys)->not->toBeEmpty();

    $missing = array_diff($conditionKeys, $dispatchKeys);

    expect($missing)->toBe([], 'inscribeUnmetReason() checks a condition with no matching case in resolveInscribe(): '.implode(', ', $missing));
});
