<?php

namespace App\Game\Catalog;

/**
 * One row from resources/data/canticle/relics.json. `effect` is free text
 * — executing it is a bespoke, per-Relic implementation task (the "perform
 * the Action from an equipped Relic" Wanderer action is not modeled yet).
 * This DTO only covers what's needed to deal, equip, and display a Relic.
 */
final class Relic
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $effect,
        public readonly string $slot, // Hand|Bag|Mind|Body
        public readonly string $slotEmoji,
        public readonly string $symbol, // branch emoji — which deck this belongs to
        public readonly ?string $locationName, // non-null => location-locked, see rules-todo
        public readonly bool $isEvent, // resolve immediately and trash, never equipped — rules.md
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'effect' => $this->effect,
            'slot' => $this->slot,
            'slot_emoji' => $this->slotEmoji,
            'symbol' => $this->symbol,
        ];
    }
}
