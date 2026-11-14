<?php

namespace App\Http\Controllers;

use App\Game\GameSetup;
use App\Game\GameStateRepository;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GameRoleController extends Controller
{
    /**
     * Any connected player can claim any number of open roles — role
     * count is fixed by the game (2-3 Wanderers + Emperor + optional
     * Golem-pilot), player count is not. Solo play claims every role.
     */
    public function claim(Game $game, string $roleKey): RedirectResponse
    {
        $role = $game->roles()->where('role_key', $roleKey)->firstOrFail();

        if ($role->user_id !== null && $role->user_id !== Auth::id()) {
            throw ValidationException::withMessages(['role' => 'That role is already taken.']);
        }

        $role->update(['user_id' => Auth::id()]);

        return back();
    }

    public function release(Game $game, string $roleKey): RedirectResponse
    {
        $role = $game->roles()->where('role_key', $roleKey)->where('user_id', Auth::id())->first();

        $role?->update(['user_id' => null, 'is_ready' => false]);

        return back();
    }

    public function ready(Game $game, string $roleKey): RedirectResponse
    {
        $role = $game->roles()->where('role_key', $roleKey)->where('user_id', Auth::id())->firstOrFail();
        $role->update(['is_ready' => ! $role->is_ready]);

        return back();
    }

    public function start(Game $game): RedirectResponse
    {
        if ($game->created_by_user_id !== Auth::id()) {
            abort(403, 'Only the host can do that.');
        }

        $game->loadMissing('roles');

        if ($game->status !== 'lobby') {
            return to_route('games.show', $game);
        }

        if ($game->roles->contains(fn ($role) => $role->user_id === null)) {
            throw ValidationException::withMessages(['roles' => 'Every role must be claimed before starting.']);
        }

        if ($game->roles->contains(fn ($role) => ! $role->is_ready)) {
            throw ValidationException::withMessages(['roles' => 'Not everyone is ready yet.']);
        }

        $state = GameSetup::create($game->wanderer_count);

        $game->update(['status' => 'active']);
        GameStateRepository::persist($game, $state);

        return to_route('games.show', $game);
    }

    public function abandon(Game $game): RedirectResponse
    {
        if ($game->created_by_user_id !== Auth::id()) {
            abort(403, 'Only the host can do that.');
        }

        if (! in_array($game->status, ['lobby', 'active'], true)) {
            return to_route('games.show', $game);
        }

        $game->update(['status' => 'abandoned']);

        return to_route('home');
    }
}
