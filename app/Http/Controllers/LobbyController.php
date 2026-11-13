<?php

namespace App\Http\Controllers;

use App\Game\RoleKeys;
use App\Models\Game;
use App\Models\GameRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class LobbyController extends Controller
{
    public function index(): Response
    {
        $games = Game::query()
            ->where('status', 'lobby')
            ->withCount('roles')
            ->latest()
            ->get()
            ->map(fn (Game $game) => [
                'code' => $game->code,
                'wanderer_count' => $game->wanderer_count,
                'golem_enabled' => $game->golem_enabled,
                'roles_filled' => $game->roles()->whereNotNull('user_id')->count(),
                'roles_total' => $game->roles_count,
            ]);

        return Inertia::render('welcome', ['games' => $games]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'wanderer_count' => ['required', 'integer', 'in:2,3'],
            'golem_enabled' => ['sometimes', 'boolean'],
        ]);

        $game = Game::create([
            'code' => $this->generateCode(),
            'status' => 'lobby',
            'wanderer_count' => $validated['wanderer_count'],
            'golem_enabled' => $validated['golem_enabled'] ?? false,
            'created_by_user_id' => Auth::id(),
        ]);

        foreach (RoleKeys::all($game->wanderer_count, $game->golem_enabled) as $roleKey) {
            GameRole::create(['game_id' => $game->id, 'role_key' => $roleKey]);
        }

        return to_route('games.show', $game);
    }

    public function join(Request $request, string $code): RedirectResponse
    {
        Game::where('code', strtoupper($code))->where('status', 'lobby')->firstOrFail();

        return to_route('games.show', strtoupper($code));
    }

    private function generateCode(): string
    {
        do {
            $code = Str::upper(Str::random(6));
        } while (Game::where('code', $code)->exists());

        return $code;
    }
}
