<?php

namespace App\Game\Catalog;

/**
 * One row from resources/data/canticle/golem_cards.json. `action` is free
 * text — see GameEngine::resolveGolemCard for which of these 29 cards
 * have a hand-implemented behavior vs. are logged-but-not-executed.
 */
final class GolemCard
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $action,
    ) {}
}
