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

    /** The 8 branch emojis a Relic's `symbol` ever takes (no Relics in Agriculture/Commerce/Engineering). */
    private const RELIC_SYMBOLS = ['⏳', '⚖️', '✨', '🌑', '🎨', '🐾', '📖', '🤝'];

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
            'relicSummary' => $this->buildRelicSummary(),
        ];
    }

    /**
     * Counts per slot, per symbol (branch), and the slot x symbol
     * matrix — excludes soft-deleted Relics (the default query scope),
     * same "currently live" population the list itself shows unless
     * "Show deleted" is checked.
     *
     * Deliberately NOT `->groupBy('slot', 'symbol')` in SQL: MySQL's
     * utf8mb4_unicode_ci/utf8mb4_general_ci collations have incomplete
     * weight tables for supplementary-plane (4-byte) characters, which
     * is exactly what every Relic symbol except ⏳/⚖️/✨ is — GROUP BY
     * silently treated 🌑/🎨/🐾/📖/🤝 as equal to each other on prod
     * (confirmed: the stored bytes are correct, a plain SELECT reads
     * them back fine; only the grouped aggregate merged them, all into
     * whichever one happened to be the "first" of the group). Tallying
     * in PHP after a plain SELECT sidesteps the collation entirely —
     * fine at this scale (~100 rows).
     */
    private function buildRelicSummary(): array
    {
        $slots = array_keys(self::SLOT_EMOJI);
        $symbols = self::RELIC_SYMBOLS;

        $matrix = [];
        foreach ($slots as $slot) {
            $matrix[$slot] = array_fill_keys($symbols, 0);
        }

        foreach (Relic::query()->get(['slot', 'symbol']) as $relic) {
            if (isset($matrix[$relic->slot][$relic->symbol])) {
                $matrix[$relic->slot][$relic->symbol]++;
            }
        }

        return [
            'slots' => $slots,
            'columns' => $symbols,
            'matrix' => $matrix,
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
