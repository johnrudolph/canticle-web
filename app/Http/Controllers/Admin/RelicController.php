<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\Relic;

class RelicController extends CardController
{
    protected function modelClass(): string
    {
        return Relic::class;
    }

    protected function morphType(): string
    {
        return 'relic';
    }

    protected function pageComponent(): string
    {
        return 'admin/relics';
    }

    protected function rules(): array
    {
        return [
            'print' => ['boolean'],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'slot' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'symbol' => ['nullable', 'string', 'max:255'],
            'effect' => ['nullable', 'string'],
            'slot_emoji' => ['nullable', 'string', 'max:255'],
            'is_action' => ['boolean'],
            'is_passive' => ['boolean'],
            'is_event' => ['boolean'],
        ];
    }
}
