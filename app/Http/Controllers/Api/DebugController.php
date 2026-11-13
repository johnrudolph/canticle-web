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
            ->withCount('seats')
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (Game $game) => [
                'id' => $game->id,
                'code' => $game->code,
                'status' => $game->status,
                'player_count' => $game->player_count,
                'seats_filled' => $game->seats_count,
                'created_at' => $game->created_at,
                'updated_at' => $game->updated_at,
            ]);

        return response()->json(['games' => $games]);
    }

    public function show(Game $game): JsonResponse
    {
        $game->load(['creator', 'seats.user']);

        return response()->json(['game' => $game]);
    }
}
