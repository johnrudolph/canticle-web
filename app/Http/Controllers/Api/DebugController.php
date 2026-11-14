<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;

/**
 * Read-only production debug API, gated by EnsureDebugApiKey.
 */
class DebugController extends Controller
{
    public function index(): JsonResponse
    {
        $games = Game::query()
            ->withCount('roles')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'code' => $game->code,
                'status' => $game->status,
                'wanderer_count' => $game->wanderer_count,
                'golem_enabled' => $game->golem_enabled,
                'round' => $game->round,
                'phase' => $game->phase,
                'roles_count' => $game->roles_count,
                'created_at' => $game->created_at,
                'updated_at' => $game->updated_at,
            ]);

        return response()->json(['games' => $games]);
    }

    public function show(Game $game): JsonResponse
    {
        $game->load(['creator', 'roles.user']);

        $events = $game->events()->latest('id')->limit(300)->get()->reverse()->values();

        return response()->json(['game' => $game, 'events' => $events]);
    }
}
