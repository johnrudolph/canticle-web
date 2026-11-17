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

    /**
     * @return array{name: string, type: string, description: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'description' => $this->description,
        ];
    }
}
