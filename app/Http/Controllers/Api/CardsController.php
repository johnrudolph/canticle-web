<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cards\Glyph;
use App\Models\Cards\GolemCard;
use App\Models\Cards\Location;
use App\Models\Cards\Relic;
use Illuminate\Http\JsonResponse;

/**
 * Public, read-only, unauthenticated — the full current card set (every
 * column, including the ones that are "computed" on the old Google
 * Sheet, since the Figma plugin this feeds still relies on them). No
 * sensitive data lives on these models, same as the JSON this replaces
 * being bundled straight into the app before.
 */
class CardsController extends Controller
{
    public function index(): JsonResponse
    {
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
}
