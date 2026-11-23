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
        return response()->json([
            'locations' => Location::query()->orderBy('name')->get(),
            'relics' => Relic::query()->orderBy('name')->get(),
            'glyphs' => Glyph::query()->orderBy('name')->get(),
            'golem_cards' => GolemCard::query()->orderBy('name')->get(),
        ]);
    }
}
