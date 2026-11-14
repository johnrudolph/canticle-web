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

test('the Emperor draw deck never contains a duplicate of a starting Location', function () {
    $state = GameSetup::create(2);

    $startingKeys = array_map(fn ($cell) => $cell['location_key'], $state->board);

    foreach ($startingKeys as $key) {
        expect($state->locationDeck)->not->toContain($key);
        expect($state->locationHand)->not->toContain($key);
    }
});
