<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\Glyph;

class GlyphController extends CardController
{
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

    protected function rules(): array
    {
        return [
            'print' => ['boolean'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ];
    }
}
