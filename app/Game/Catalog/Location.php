<?php

namespace App\Game\Catalog;

/**
 * One row from resources/data/canticle/locations.json, normalized. Relic
 * drawing and the ⭐/has_specific_relics rule are deliberately not modeled
 * yet — only what's needed to place a Location on the board (name, lore
 * text, what it produces, what it costs) plus its fixed-at-build-time
 * Infrastructure fixtures ($hasShard/$hasTree).
 */
final class Location
{
    /**
     * @param  list<string>  $costs  Branch names this Location must be adjacent to (AND'd, each may be satisfied by a different neighbor).
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $lore,
        public readonly ?string $backText,
        public readonly ?string $produces,
        public readonly ?string $producesEmoji,
        public readonly array $costs,
        public readonly int $relicCount,
        public readonly ?string $dealsFromBranch, // which Relic deck this Location's pile is dealt from — usually the same symbol as $producesEmoji, but not always (Beds of poison deals 🌑 Relics despite producing nothing adjacency-wise); null for the few Locations with dynamic, city-wide dealing (Bazaar, Library) instead of a fixed branch
        public readonly bool $isStarter,
        public readonly bool $hasShard,
        public readonly bool $hasTree,
    ) {}

    /**
     * @return array{key: string, name: string, lore: string, back_text: ?string, produces: ?string, produces_emoji: ?string, costs: list<string>, relic_count: int}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'lore' => $this->lore,
            'back_text' => $this->backText,
            'produces' => $this->produces,
            'produces_emoji' => $this->producesEmoji,
            'costs' => $this->costs,
            'relic_count' => $this->relicCount,
        ];
    }
}
