<?php

namespace App\Game;

use App\Game\Catalog\Location;
use App\Game\Catalog\LocationCatalog;
use App\Game\Catalog\RelicCatalog;

/**
 * Deals a Location's Relic pile off its branch's shared deck. Location-
 * locked Relics (printed with this Location's name on the physical card
 * back) are always included first when their own Location is built —
 * rules.md: "If you come across Relics with backs that specify a
 * Location, skip them unless you have drawn that Location." Locked Relics
 * belonging to some *other*, not-yet-built Location are therefore never
 * eligible as random filler — they stay parked in the deck, in place,
 * until their own Location shows up.
 *
 * Branches with no Relic deck at all (Agriculture, Commerce, Engineering —
 * confirmed against key.json, which lists no Relic type for them) simply
 * deal nothing.
 */
final class RelicDealer
{
    /**
     * Entry point for dealing any Location's Relic pile — routes Bazaar to
     * its special city-wide dealing, everything else to the normal
     * single-branch deal().
     *
     * @param  array<string, list<string>>  $relicDecks
     * @param  array<string, array{location_key: string, placed_round: int, relics: list<string>, relics_face_up: list<string>, infrastructure: list<string>, tucked: list<string>}>  $board
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    public static function dealForLocation(array $relicDecks, Location $location, array $board): array
    {
        if ($location->key === 'bazaar') {
            return self::dealBazaar($relicDecks, $board);
        }

        return self::deal($relicDecks, $location);
    }

    /**
     * @param  array<string, list<string>>  $relicDecks
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    public static function deal(array $relicDecks, Location $location): array
    {
        if ($location->producesEmoji === null || $location->relicCount === 0) {
            return [[], $relicDecks];
        }

        $deck = $relicDecks[$location->producesEmoji] ?? [];

        $locked = array_values(array_intersect(
            RelicCatalog::keysLockedToLocation($location->name),
            $deck
        ));

        $remaining = array_values(array_diff($deck, $locked));
        $needed = max(0, $location->relicCount - count($locked));

        $randomPicks = [];
        $leftover = [];
        foreach ($remaining as $key) {
            if (count($randomPicks) < $needed && RelicCatalog::find($key)?->locationName === null) {
                $randomPicks[] = $key;
            } else {
                $leftover[] = $key;
            }
        }

        $dealt = [...$locked, ...$randomPicks];
        $relicDecks[$location->producesEmoji] = $leftover;

        return [$dealt, $relicDecks];
    }

    /**
     * Bazaar's Relic count isn't a plain number — rules.md: "1 of each
     * type already produced by the city." Pulls one Relic from every
     * branch deck that has at least one Location built, instead of
     * dealing multiple from its own (nonexistent — Commerce has no Relic
     * deck at all) branch.
     *
     * @param  array<string, list<string>>  $relicDecks
     * @param  array<string, array{location_key: string, placed_round: int, relics: list<string>, relics_face_up: list<string>, infrastructure: list<string>, tucked: list<string>}>  $board
     * @return array{0: list<string>, 1: array<string, list<string>>}
     */
    public static function dealBazaar(array $relicDecks, array $board): array
    {
        $producedEmojis = [];

        foreach ($board as $entry) {
            $location = LocationCatalog::find($entry['location_key']);

            if ($location?->producesEmoji !== null) {
                $producedEmojis[$location->producesEmoji] = true;
            }
        }

        $dealt = [];

        foreach (array_keys($producedEmojis) as $emoji) {
            if (($relicDecks[$emoji] ?? []) === []) {
                continue;
            }

            $picked = array_shift($relicDecks[$emoji]);
            $dealt[] = $picked;
        }

        return [$dealt, $relicDecks];
    }
}
