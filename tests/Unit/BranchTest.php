<?php

use App\Models\Cards\Branch;

test('every Branch case has an emoji', function () {
    foreach (Branch::cases() as $branch) {
        expect($branch->emoji())->not->toBe('');
    }
});

test('relicSymbols excludes Agriculture/Commerce, nothing else', function () {
    $withRelicNames = array_map(fn (Branch $b) => $b->name, Branch::withRelics());
    $excludedNames = array_values(array_diff(
        array_map(fn (Branch $b) => $b->name, Branch::cases()),
        $withRelicNames,
    ));

    expect($excludedNames)->toEqualCanonicalizing(['Agriculture', 'Commerce'])
        ->and(count(Branch::relicSymbols()))->toBe(count(Branch::cases()) - 2);
});

test('Engineering is no longer a valid branch — removed once nothing produced/cost it on prod', function () {
    expect(array_keys(Branch::emojiMap()))->not->toContain('Engineering')
        ->and(Branch::relicSymbols())->not->toContain('⚙️');
});

test('emojiMap and relicSymbols stay in sync with each other for every shared branch', function () {
    $emojiMap = Branch::emojiMap();

    foreach (Branch::withRelics() as $branch) {
        expect(Branch::relicSymbols())->toContain($emojiMap[$branch->value]);
    }
});
