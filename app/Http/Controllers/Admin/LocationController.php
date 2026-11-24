<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\Location;
use App\Models\Cards\Relic;

class LocationController extends CardController
{
    /** Branch name -> its emoji — same mapping Location rows already carry as produces/produces_emoji pairs. */
    private const BRANCH_EMOJI = [
        'Agriculture' => '🌾',
        'Art' => '🎨',
        'Commerce' => '🪙',
        'Dark' => '🌑',
        'Engineering' => '⚙️',
        'Golem' => '🐾',
        'Law' => '⚖️',
        'Magic' => '✨',
        'Religion' => '📖',
        'Solidarity' => '🤝',
        'Time' => '⏳',
    ];

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

    protected function detailPageComponent(): string
    {
        return 'admin/location-detail';
    }

    protected function extraProps(): array
    {
        return [
            'branchSummary' => $this->buildBranchSummary(),
        ];
    }

    /**
     * Per-branch counts: how many Relics belong to that branch (by
     * `symbol`) side by side with how many Locations `produces` it.
     *
     * Like RelicController::buildRelicSummary(), this deliberately
     * avoids SQL `GROUP BY` on the emoji-valued Relic.symbol column —
     * MySQL's utf8mb4_unicode_ci/utf8mb4_general_ci collations merge
     * distinct 4-byte emoji under GROUP BY. Location.produces is a
     * plain ASCII branch name, so it's safe to tally either way, but
     * tallying both in PHP keeps the two counts built the same way.
     */
    private function buildBranchSummary(): array
    {
        $branches = array_keys(self::BRANCH_EMOJI);

        $locationCounts = array_fill_keys($branches, 0);
        foreach (Location::query()->pluck('produces') as $produces) {
            if (isset($locationCounts[$produces])) {
                $locationCounts[$produces]++;
            }
        }

        $branchBySymbol = array_flip(self::BRANCH_EMOJI);
        $relicCounts = array_fill_keys($branches, 0);
        foreach (Relic::query()->pluck('symbol') as $symbol) {
            $branch = $branchBySymbol[$symbol] ?? null;
            if ($branch !== null) {
                $relicCounts[$branch]++;
            }
        }

        return [
            'branches' => $branches,
            'relicCounts' => $relicCounts,
            'locationCounts' => $locationCounts,
        ];
    }

    protected function rules(): array
    {
        $branches = implode(',', array_keys(self::BRANCH_EMOJI));

        return [
            'print' => ['boolean'],
            'name' => ['required', 'string', 'max:255'],
            'lore' => ['nullable', 'string'],
            'rule' => ['nullable', 'string'],
            'costs' => ['array', 'max:4'],
            'costs.*' => ['string', "in:{$branches}"],
            'produces' => ['nullable', 'string', "in:{$branches},"],
            'relics' => ['nullable', 'string', 'max:255'],
            'is_starter' => ['boolean'],
            'has_shard' => ['boolean'],
            'has_tree' => ['boolean'],
        ];
    }

    protected function beforeSave(array $data): array
    {
        $costs = array_values($data['costs'] ?? []);
        unset($data['costs']);

        for ($i = 0; $i < 4; $i++) {
            $data['cost_'.($i + 1)] = $costs[$i] ?? '';
        }

        $data['cost_emoji'] = implode('', array_map(
            fn (string $branch) => self::BRANCH_EMOJI[$branch] ?? '',
            $costs,
        ));

        $data['produces_emoji'] = self::BRANCH_EMOJI[$data['produces'] ?? ''] ?? '';
        $data['has_cost'] = $costs !== [];
        $data['produces_anything'] = ($data['produces'] ?? '') !== '';
        $data['has_back_text'] = trim((string) ($data['rule'] ?? '')) !== '';
        $data['has_relics'] = trim((string) ($data['relics'] ?? '')) !== '';

        // relic_names isn't something anyone types in — it's just which
        // Relics currently point at this Location by name (Relic.location,
        // the rare ⭐ location-locked case). Recomputed every time this
        // Location is saved; not used by any gameplay logic, purely
        // reference text for the Emperor/Figma plugin.
        $relicNames = Relic::query()
            ->where('location', $data['name'])
            ->orderBy('name')
            ->pluck('name')
            ->implode(', ');
        $data['relic_names'] = $relicNames;
        $data['has_specific_relics'] = $relicNames !== '';

        return $data;
    }
}
