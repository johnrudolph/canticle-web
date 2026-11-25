<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\Branch;
use App\Models\Cards\Glyph;

class GlyphController extends CardController
{
    /** The malevolent/self-inscription marker — not a branch, so it's not in Branch, but a Glyph's `type` can still take it. */
    private const MALEVOLENT_MARKER = '👺';

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

    protected function extraProps(): array
    {
        return [
            // Single source of truth for the frontend's type picker —
            // see Branch's own docblock for why this exists instead of
            // a second hardcoded copy in admin-columns.ts.
            'glyphTypes' => [...Branch::relicSymbols(), self::MALEVOLENT_MARKER],
        ];
    }

    protected function rules(): array
    {
        $types = implode(',', [...Branch::relicSymbols(), self::MALEVOLENT_MARKER]);

        return [
            'print' => ['boolean'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', "in:{$types},"],
            'description' => ['nullable', 'string'],
        ];
    }
}
