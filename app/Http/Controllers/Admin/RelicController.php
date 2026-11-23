<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\Location;
use App\Models\Cards\Relic;

class RelicController extends CardController
{
    /** Hand/Mind/Body/Bag -> slot_emoji. Empty slot (Event relics) -> empty. */
    private const SLOT_EMOJI = [
        'Body' => '🥋',
        'Mind' => '🧠',
        'Hand' => '🖐️',
        'Bag' => '👝',
        '' => '',
    ];

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

    protected function detailPageComponent(): string
    {
        return 'admin/relic-detail';
    }

    protected function extraProps(): array
    {
        return [
            'locationNames' => Location::query()->orderBy('name')->pluck('name'),
        ];
    }

    protected function rules(): array
    {
        return [
            'print' => ['boolean'],
            'name' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:255'],
            'slot' => ['required', 'string', 'in:Body,Mind,Hand,Bag,'],
            'type' => ['required', 'string', 'in:Action,Passive,Event'],
            'symbol' => ['nullable', 'string', 'in:⏳,⚖️,✨,🌑,🎨,🐾,📖,🤝,'],
            'effect' => ['nullable', 'string'],
        ];
    }

    protected function beforeSave(array $data): array
    {
        $data['slot_emoji'] = self::SLOT_EMOJI[$data['slot']] ?? '';
        $data['is_action'] = $data['type'] === 'Action';
        $data['is_passive'] = $data['type'] === 'Passive';
        $data['is_event'] = $data['type'] === 'Event';

        return $data;
    }
}
