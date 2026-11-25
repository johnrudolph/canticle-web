<?php

namespace App\Http\Controllers;

use App\Game\Catalog\LocationCatalog;
use App\Game\GameSetup;
use App\Game\GameStateRepository;
use App\Game\RoleKeys;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $role?->update(['user_id' => null]);

        return back();
    }

    /**
     * "Once you know the game better, the Emperor may choose their 6
     * starting locations." Lobby-only: whoever is piloting the Emperor
     * role picks the 6 starters ahead of time, stored on the Game row
     * until the host actually starts it. Only valid is_starter keys are
     * accepted (starting Locations must never have a build cost — see
     * is_starter's meaning in GameSetup::create()), and Advanced ones
     * are excluded too when this game also has exclude_advanced_locations
     * on, so the Emperor can't accidentally defeat that other toggle.
     */
    public function selectStartingLocations(Game $game, Request $request): RedirectResponse
    {
        $isEmperor = $game->roles()
            ->where('role_key', RoleKeys::EMPEROR)
            ->where('user_id', Auth::id())
            ->exists();

        if (! $isEmperor) {
            abort(403, 'Only the Emperor can do that.');
        }

        if ($game->status !== 'lobby' || ! $game->emperor_picks_starting_locations) {
            return to_route('games.show', $game);
        }

        $validated = $request->validate([
            'location_keys' => ['required', 'array', 'size:6'],
            'location_keys.*' => ['string', 'distinct'],
        ]);

        $validKeys = $this->eligibleStarterKeys($game);

        if (array_diff($validated['location_keys'], $validKeys) !== []) {
            throw ValidationException::withMessages(['location_keys' => 'Invalid starting Location selection.']);
        }

        $game->update(['starting_location_keys' => $validated['location_keys']]);

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

        if ($game->emperor_picks_starting_locations && $game->starting_location_keys === null) {
            throw ValidationException::withMessages(['starting_location_keys' => 'The Emperor must choose their 6 starting Locations first.']);
        }

        $state = GameSetup::create(
            $game->wanderer_count,
            $game->exclude_advanced_locations,
            $game->starting_location_keys,
        );

        $game->update(['status' => 'active']);
        GameStateRepository::persist($game, $state);

        return to_route('games.show', $game);
    }

    /**
     * @return list<string>
     */
    private function eligibleStarterKeys(Game $game): array
    {
        return array_values(array_filter(
            array_keys(LocationCatalog::all()),
            fn (string $key) => LocationCatalog::find($key)?->isStarter === true
                && (! $game->exclude_advanced_locations || LocationCatalog::find($key)?->isAdvanced !== true),
        ));
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
