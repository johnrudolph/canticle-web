<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\Location;

class LocationController extends CardController
{
    protected function modelClass(): string
    {
        return Location::class;
    }

    protected function morphType(): string
    {
        return 'location';
    }

    protected function pageComponent(): string
    {
        return 'admin/locations';
    }

    protected function rules(): array
    {
        return [
            'print' => ['boolean'],
            'name' => ['required', 'string', 'max:255'],
            'lore' => ['nullable', 'string'],
            'rule' => ['nullable', 'string'],
            'cost_1' => ['nullable', 'string', 'max:255'],
            'cost_2' => ['nullable', 'string', 'max:255'],
            'cost_3' => ['nullable', 'string', 'max:255'],
            'cost_4' => ['nullable', 'string', 'max:255'],
            'produces' => ['nullable', 'string', 'max:255'],
            'produces_emoji' => ['nullable', 'string', 'max:255'],
            'relics' => ['nullable', 'string', 'max:255'],
            'relic_names' => ['nullable', 'string'],
            'is_starter' => ['boolean'],
            'has_shard' => ['boolean'],
            'has_tree' => ['boolean'],
            'has_back_text' => ['boolean'],
            'has_relics' => ['boolean'],
            'has_specific_relics' => ['boolean'],
            'produces_anything' => ['boolean'],
            'has_cost' => ['boolean'],
            'cost_emoji' => ['nullable', 'string', 'max:255'],
        ];
    }
}
