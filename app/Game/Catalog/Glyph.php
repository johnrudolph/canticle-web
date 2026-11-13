<?php

namespace App\Game\Catalog;

final class Glyph
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $type, // an emoji — which glyph-type deck this belongs to
        public readonly string $description,
    ) {}
}
