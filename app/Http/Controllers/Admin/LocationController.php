<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\Branch;
use App\Models\Cards\Location;
use App\Models\Cards\Relic;
use Inertia\Inertia;
use Inertia\Response;

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

    protected function detailPageComponent(): string
    {
        return 'admin/location-detail';
    }

    protected function extraProps(): array
    {
        return [
            'branchSummary' => $this->buildBranchSummary(),
            // Single source of truth for the frontend's produces/costs
            // pickers — see Branch's own docblock for why this exists
            // instead of a second hardcoded copy in admin-columns.ts.
            'branches' => array_keys(Branch::emojiMap()),
        ];
    }

    /**
     * Same {id, name} location-locked Relic list show() attaches for
     * the detail page's link list — also attached per row here so the
     * list table's "Required relics" column can link straight to each
     * one without an extra round trip per card.
     */
    public function index(): Response
    {
        $cards = Location::query()->withTrashed()->with('comments')->orderBy('name')->get();

        $relicsByLocation = Relic::query()
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->orderBy('name')
            ->get(['id', 'name', 'location'])
            ->groupBy('location');

        foreach ($cards as $card) {
            $card->setAttribute(
                'linked_relics',
                ($relicsByLocation->get($card->name) ?? collect())->map(fn (Relic $r) => ['id' => $r->id, 'name' => $r->name])->values(),
            );
        }

        return Inertia::render($this->pageComponent(), [
            'cards' => $cards,
            ...$this->extraProps(),
        ]);
    }

    /**
     * The rare ⭐ location-locked Relics (Relic.location, matched by
     * name) that point at this specific Location — same relation
     * relic_names already summarizes as plain text, just also handed
     * over as {id, name} pairs so the detail page can link each one
     * straight to its own Relic admin page.
     */
    public function show(int $id): Response
    {
        $card = Location::query()->withTrashed()->with('comments')->findOrFail($id);

        $linkedRelics = Relic::query()
            ->where('location', $card->name)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render($this->detailPageComponent(), [
            'card' => $card,
            'linkedRelics' => $linkedRelics,
            ...$this->extraProps(),
        ]);
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
        $emojiMap = Branch::emojiMap();
        $branches = array_keys($emojiMap);

        $locationCounts = array_fill_keys($branches, 0);
        foreach (Location::query()->pluck('produces') as $produces) {
            if (isset($locationCounts[$produces])) {
                $locationCounts[$produces]++;
            }
        }

        $branchBySymbol = array_flip($emojiMap);
        $relicCounts = array_fill_keys($branches, 0);
        foreach (Relic::query()->pluck('symbol') as $symbol) {
            $branch = $branchBySymbol[$symbol] ?? null;
            if ($branch !== null) {
                $relicCounts[$branch]++;
            }
        }

        return [
            'branches' => $branches,
            'emoji' => $emojiMap,
            'relicCounts' => $relicCounts,
            'locationCounts' => $locationCounts,
        ];
    }

    protected function rules(): array
    {
        $branches = implode(',', array_keys(Branch::emojiMap()));

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
            'is_advanced' => ['boolean'],
            'has_shard' => ['boolean'],
            'has_tree' => ['boolean'],
        ];
    }

    protected function beforeSave(array $data): array
    {
        $emojiMap = Branch::emojiMap();
        $costs = array_values($data['costs'] ?? []);
        unset($data['costs']);

        for ($i = 0; $i < 4; $i++) {
            $data['cost_'.($i + 1)] = $costs[$i] ?? '';
        }

        $data['cost_emoji'] = implode('', array_map(
            fn (string $branch) => $emojiMap[$branch] ?? '',
            $costs,
        ));

        $data['produces_emoji'] = $emojiMap[$data['produces'] ?? ''] ?? '';
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
