<?php

namespace App\Game\Catalog;

/**
 * One row from resources/data/canticle/locations.json, normalized. Relic
 * drawing, infrastructure fixtures, and the ⭐/has_specific_relics rule are
 * deliberately not modeled yet — only what's needed to place a Location on
 * the board (name, lore text, what it produces, what it costs).
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
    ) {}

    /**
     * @return array<string, mixed>
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
        ];
    }
}
