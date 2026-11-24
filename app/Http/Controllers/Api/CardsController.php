<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cards\Glyph;
use App\Models\Cards\GolemCard;
use App\Models\Cards\Location;
use App\Models\Cards\Relic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, read-only, unauthenticated — the full current card set (every
 * column, including the ones that are "computed" on the old Google
 * Sheet, since the Figma plugin this feeds still relies on them). No
 * sensitive data lives on these models, same as the JSON this replaces
 * being bundled straight into the app before.
 *
 * Two shapes off the same route, chosen by an optional ?type= query
 * param:
 *  - no `type` (canticle:pull-changes's own usage): the original
 *    nested {locations, relics, glyphs, golem_cards} shape, each a
 *    full object with every raw column.
 *  - `type=location|relic|glyph|golem_card` (the Figma plugin's own
 *    usage, one request per configured card type): a flat array of
 *    that type's raw rows, no wrapper. Deliberately NOT a single
 *    flattened-with-a-"type"-tag array across all 4 types — Relic
 *    rows already have their own `type` column (Action/Passive/
 *    Event), so tagging a card-category "type" into the same key
 *    would silently clobber it. Per-type requests sidestep that
 *    collision entirely: the plugin already knows which category it
 *    asked for.
 */
class CardsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $type = $request->query('type');

        if ($type !== null) {
            return response()->json($this->rowsForType((string) $type));
        }

        // Ordered by id (original sheet/insertion order), same as every
        // *Catalog class — not just arbitrary consistency: canticle:pull-
        // changes writes this response straight into resources/data/
        // canticle/*.json, and an alphabetical re-sort on every pull
        // would reorder (and so diff) the entire file even when nothing
        // actually changed.
        return response()->json([
            'locations' => Location::query()->orderBy('id')->get(),
            'relics' => Relic::query()->orderBy('id')->get(),
            'glyphs' => Glyph::query()->orderBy('id')->get(),
            'golem_cards' => GolemCard::query()->orderBy('id')->get(),
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>
     */
    private function rowsForType(string $type): \Illuminate\Support\Collection
    {
        $modelClass = match (strtolower($type)) {
            'location', 'locations' => Location::class,
            'relic', 'relics' => Relic::class,
            'glyph', 'glyphs' => Glyph::class,
            'golem_card', 'golem_cards', 'golem-card', 'golem-cards' => GolemCard::class,
            default => null,
        };

        if ($modelClass === null) {
            return collect();
        }

        return $modelClass::query()->orderBy('id')->get();
    }
}
