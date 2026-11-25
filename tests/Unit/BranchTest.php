<?php

use App\Models\Cards\Branch;

test('every Branch case has an emoji', function () {
    foreach (Branch::cases() as $branch) {
        expect($branch->emoji())->not->toBe('');
    }
});

test('relicSymbols excludes Agriculture/Commerce/Engineering, nothing else', function () {
    $withRelicNames = array_map(fn (Branch $b) => $b->name, Branch::withRelics());
    $excludedNames = array_values(array_diff(
        array_map(fn (Branch $b) => $b->name, Branch::cases()),
        $withRelicNames,
    ));

    expect($excludedNames)->toEqualCanonicalizing(['Agriculture', 'Commerce', 'Engineering'])
        ->and(count(Branch::relicSymbols()))->toBe(count(Branch::cases()) - 3);
});

test('emojiMap and relicSymbols stay in sync with each other for every shared branch', function () {
    $emojiMap = Branch::emojiMap();

    foreach (Branch::withRelics() as $branch) {
        expect(Branch::relicSymbols())->toContain($emojiMap[$branch->value]);
    }
});
