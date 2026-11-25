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

test('Bazaar as a starting Location sees every other starting Location\'s branch, regardless of setup iteration order', function () {
    // Bazaar's Relic pile ("1 of each type already produced by the
    // city") used to be dealt mid-way through the same loop that
    // places the 6 starting Locations onto the board, so it only ever
    // saw whichever starters happened to be placed earlier in that
    // loop's iteration order — confirmed in production, game LPJGX6:
    // Bazaar as a starter, with 2 other Relic-producing starters, only
    // dealt 1 Relic instead of 3. GameSetup::create() now places every
    // starting Location's location_key first, then deals every
    // starter's Relics (including Bazaar's) in a second pass.
    $sawBazaar = false;

    for ($i = 0; $i < 60; $i++) {
        $state = GameSetup::create(2);

        $bazaarCell = null;
        foreach ($state->board as $cell) {
            if ($cell['location_key'] === 'bazaar') {
                $bazaarCell = $cell;
                break;
            }
        }

        if ($bazaarCell === null) {
            continue;
        }

        $sawBazaar = true;

        // Agriculture (🌾) and Commerce (🪙) have no Relic deck at all
        // (see RelicDealer's docblock) — Bazaar can't deal from a
        // branch that has nothing to deal, so those don't count toward
        // the expected total even if a starter produces them.
        // Engineering (⚙️) used to be in this list too — retired once
        // no Location produces/costs it anymore (App\Models\Cards\
        // Branch no longer has a case for it).
        $branchesWithNoDeck = ['🌾', '🪙'];

        $producedEmojis = [];
        foreach ($state->board as $cell) {
            if ($cell['location_key'] === 'bazaar') {
                continue;
            }

            $location = LocationCatalog::find($cell['location_key']);

            if ($location?->producesEmoji !== null && ! in_array($location->producesEmoji, $branchesWithNoDeck, true)) {
                $producedEmojis[$location->producesEmoji] = true;
            }
        }

        expect(count($bazaarCell['relics']))->toBe(count($producedEmojis));
    }

    expect($sawBazaar)->toBeTrue();
});

test('the Emperor draw deck never contains a duplicate of a starting Location', function () {
    $state = GameSetup::create(2);

    $startingKeys = array_map(fn ($cell) => $cell['location_key'], $state->board);

    foreach ($startingKeys as $key) {
        expect($state->locationDeck)->not->toContain($key);
        expect($state->locationHand)->not->toContain($key);
    }
});
