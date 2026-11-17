<?php

use App\Game\Catalog\LocationCatalog;
use App\Game\GameSetup;

test('every starting Location is cost-free, across repeated shuffles', function () {
    for ($i = 0; $i < 25; $i++) {
        $state = GameSetup::create(2);

        foreach ($state->board as $cell) {
            $location = LocationCatalog::find($cell['location_key']);
            expect($location?->costs)->toBe([]);
        }
    }
});

test('starting Locations marked has_shard/has_tree start with that fixture already placed, drawn from the pool', function () {
    for ($i = 0; $i < 25; $i++) {
        $state = GameSetup::create(2);

        $expectedShards = 0;
        $expectedTrees = 0;

        foreach ($state->board as $cell) {
            $location = LocationCatalog::find($cell['location_key']);

            expect(in_array('monolith_shard', $cell['infrastructure'], true))->toBe($location->hasShard);
            expect(in_array('fruit_tree', $cell['infrastructure'], true))->toBe($location->hasTree);

            if ($location->hasShard) {
                $expectedShards++;
            }
            if ($location->hasTree) {
                $expectedTrees++;
            }
        }

        expect($state->infrastructurePool['monolith_shard'])->toBe(12 - $expectedShards);
        expect($state->infrastructurePool['fruit_tree'])->toBe(3 - $expectedTrees);
    }
});

test('the Emperor draw deck never contains a duplicate of a starting Location', function () {
    $state = GameSetup::create(2);

    $startingKeys = array_map(fn ($cell) => $cell['location_key'], $state->board);

    foreach ($startingKeys as $key) {
        expect($state->locationDeck)->not->toContain($key);
        expect($state->locationHand)->not->toContain($key);
    }
});
