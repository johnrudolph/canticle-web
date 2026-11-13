<?php

namespace App\Game;

use App\Game\Catalog\Location;
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
}
