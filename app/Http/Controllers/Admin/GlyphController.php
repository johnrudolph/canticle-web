<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\Glyph;

class GlyphController extends CardController
{
    /**
     * The branch emojis a Glyph's `type` ever takes — same universe as
     * RelicController::RELIC_SYMBOLS (no Glyphs in Agriculture/Commerce/
     * Engineering either), plus 👺, the malevolent/self-inscription
     * marker that has no Relic-branch equivalent.
     */
    private const GLYPH_TYPES = ['⏳', '⚖️', '✨', '🌑', '🎨', '🐾', '📖', '🤝', '🔥', '🎶', '👺'];

    protected function modelClass(): string
    {
        return Glyph::class;
    }

    protected function morphType(): string
    {
        return 'glyph';
    }

    protected function pageComponent(): string
    {
        return 'admin/glyphs';
    }

    protected function detailPageComponent(): string
    {
        return 'admin/glyph-detail';
    }

    protected function rules(): array
    {
        $types = implode(',', self::GLYPH_TYPES);

        return [
            'print' => ['boolean'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', "in:{$types},"],
            'description' => ['nullable', 'string'],
        ];
    }
}
