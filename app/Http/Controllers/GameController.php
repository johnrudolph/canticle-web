<?php

namespace App\Http\Controllers;

use App\Game\Catalog\Glyph;
use App\Game\Catalog\GlyphCatalog;
use App\Game\Catalog\GolemCard;
use App\Game\Catalog\GolemCardCatalog;
use App\Game\Catalog\Location;
use App\Game\Catalog\LocationCatalog;
use App\Game\Catalog\Relic;
use App\Game\Catalog\RelicCatalog;
use App\Game\EventLog;
use App\Game\GameEngine;
use App\Game\GameStateRepository;
use App\Game\InvalidMoveException;
use App\Models\Game;
use App\Models\GameEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GameController extends Controller
{
    public function show(Game $game): Response
    {
        $this->requireViewable($game);

        return Inertia::render('games/show', [
            'game' => ['id' => $game->id, 'code' => $game->code, ...$this->summarize($game)],
        ]);
    }

    /**
     * Polled by the client every few seconds in place of a live push
     * channel. Reverb is already running for another app on this server;
     * this one deliberately stays on plain polling — a casual board game
     * doesn't need sub-second sync, and polling avoids that extra coupling.
     */
    public function pollState(Game $game): JsonResponse
    {
        $this->requireViewable($game);

        return response()->json($this->summarize($game));
    }

    public function storeMove(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'role_key' => ['required', 'string'],
            'move' => ['required', 'array'],
        ]);

        $roleKey = $validated['role_key'];
        $game->roles()->where('role_key', $roleKey)->where('user_id', Auth::id())->firstOrFail();

        if ($game->status !== 'active') {
            throw ValidationException::withMessages(['move' => 'This game is not active.']);
        }

        $state = GameStateRepository::load($game);

        try {
            [$state, $events] = GameEngine::apply($state, $roleKey, $validated['move']);
        } catch (InvalidMoveException $e) {
            throw ValidationException::withMessages(['move' => $e->getMessage()]);
        }

        GameStateRepository::persist($game, $state);
        EventLog::record($game, $events);

        // Emperor's Timepiece: "You are now the Emperor, and they are
        // now a Wanderer." GameState has no notion of who controls which
        // role — that's GameRole's user_id<->role_key assignment — so the
        // actual swap happens here, reacting to the event GameEngine
        // emitted rather than inside the engine itself.
        foreach ($events as $event) {
            if ($event['type'] === 'emperor_timepiece_swap') {
                $this->swapRoles($game, $event['payload']['new_emperor_role'], $event['payload']['new_wanderer_role']);
            }
        }

        if ($state->phase === 'finished') {
            $game->update(['status' => 'finished']);
        }

        return response()->json($this->summarize($game->fresh()));
    }

    /**
     * Swaps which user controls each of two role_keys for this game.
     * Solo play (one user piloting both roles already) is a no-op by
     * construction — swapping a role's user_id with itself changes
     * nothing.
     */
    private function swapRoles(Game $game, string $roleA, string $roleB): void
    {
        $rowA = $game->roles()->where('role_key', $roleA)->first();
        $rowB = $game->roles()->where('role_key', $roleB)->first();

        if ($rowA === null || $rowB === null) {
            return;
        }

        [$userA, $userB] = [$rowA->user_id, $rowB->user_id];
        $rowA->update(['user_id' => $userB]);
        $rowB->update(['user_id' => $userA]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Game $game): array
    {
        $game->loadMissing('roles.user');

        $myRoles = $game->roles
            ->filter(fn ($role) => $role->user_id === Auth::id())
            ->pluck('role_key')
            ->values();

        $state = null;
        if (in_array($game->status, ['active', 'finished', 'abandoned'], true) && $game->state !== null) {
            $gameState = GameStateRepository::load($game);
            $state = $gameState->toArray();

            // The Emperor's hand is hidden information — rules.md never
            // has Wanderers see it, only "read the Location aloud" once
            // it's actually played. Redact it for anyone not piloting
            // the Emperor; location_hand_count alone is harmless and
            // already useful (e.g. "the Emperor has 5 cards").
            if (! $myRoles->contains('emperor')) {
                $state['location_hand'] = [];
            }

            // For whichever Wanderer's turn it actually is, tell the
            // frontend why each of their equipped Relics can't be used
            // right now (if at all) — same check useRelic() itself
            // enforces, so the Use button can disable itself instead of
            // letting a doomed-to-fail attempt spend the action.
            $activeRole = $state['active_wanderer_role'];
            if ($activeRole !== null) {
                $reasons = [];
                foreach ($gameState->wanderers[$activeRole]['equipped'] as $slot => $relicKey) {
                    if (is_string($relicKey) && $relicKey !== 'shame_token' && $relicKey !== 'scar_token') {
                        $reasons[$slot] = GameEngine::relicUsabilityReason($gameState, $activeRole, $relicKey);
                    }
                }
                $state['active_wanderer_relic_reasons'] = $reasons;
            } else {
                $state['active_wanderer_relic_reasons'] = [];
            }
        }

        return [
            'status' => $game->status,
            'wanderer_count' => $game->wanderer_count,
            'golem_enabled' => $game->golem_enabled,
            'is_host' => $game->created_by_user_id === Auth::id(),
            'my_roles' => $myRoles,
            'roles' => $game->roles->map(fn ($role) => [
                'role_key' => $role->role_key,
                'user_name' => $role->user?->name,
                'is_mine' => $role->user_id === Auth::id(),
            ]),
            'state' => $state,
            'location_catalog' => $this->locationCatalog(),
            'relic_catalog' => $this->relicCatalog(),
            'golem_card_catalog' => $this->golemCardCatalog(),
            'glyph_catalog' => $this->glyphCatalog(),
            'events' => $this->eventLog($game),
        ];
    }

    /**
     * @return array<string, array{name: string, lore: string, back_text: ?string, produces: ?string, produces_emoji: ?string, costs: list<string>, relic_count: int}>
     */
    private function locationCatalog(): array
    {
        return collect(LocationCatalog::all())
            ->map(fn (Location $location) => [
                'name' => $location->name,
                'lore' => $location->lore,
                'back_text' => $location->backText,
                'produces' => $location->produces,
                'produces_emoji' => $location->producesEmoji,
                'costs' => $location->costs,
                'relic_count' => $location->relicCount,
            ])
            ->all();
    }

    /**
     * @return array<string, array{name: string, effect: string, slot: string, slot_emoji: string, symbol: string, location_name: ?string, is_event: bool, is_bound: bool}>
     */
    private function relicCatalog(): array
    {
        return collect(RelicCatalog::all())
            ->map(fn (Relic $relic) => [
                'name' => $relic->name,
                'effect' => $relic->effect,
                'slot' => $relic->slot,
                'slot_emoji' => $relic->slotEmoji,
                'symbol' => $relic->symbol,
                'location_name' => $relic->locationName,
                'is_event' => $relic->isEvent,
                'is_bound' => $relic->isBound,
            ])
            ->all();
    }

    /**
     * @return array<string, array{name: string, action: string}>
     */
    private function golemCardCatalog(): array
    {
        return collect(GolemCardCatalog::all())
            ->map(fn (GolemCard $card) => ['name' => $card->name, 'action' => $card->action])
            ->all();
    }

    /**
     * @return array<string, array{name: string, type: string, description: string}>
     */
    private function glyphCatalog(): array
    {
        return collect(GlyphCatalog::all())
            ->map(fn (Glyph $glyph) => ['name' => $glyph->name, 'type' => $glyph->type, 'description' => $glyph->description])
            ->all();
    }

    /**
     * @return list<array{id: int, type: string, role_key: ?string, payload: array<string, mixed>, created_at: ?string}>
     */
    private function eventLog(Game $game): array
    {
        return array_values(
            GameEvent::where('game_id', $game->id)
                ->latest('id')
                ->limit(300)
                ->get()
                ->reverse()
                ->values()
                ->map(fn (GameEvent $event) => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'role_key' => $event->role_key,
                    'payload' => $event->payload ?? [],
                    'created_at' => $event->created_at?->toIso8601String(),
                ])
                ->all()
        );
    }

    /**
     * The lobby is open to anyone with the link, so a role can be claimed
     * in the first place — "Join by code" doesn't assign one. Once the
     * game is active, only seated roles may keep watching.
     */
    private function requireViewable(Game $game): void
    {
        if ($game->status === 'lobby') {
            return;
        }

        $hasRole = $game->roles()->where('user_id', Auth::id())->exists();

        abort_if(! $hasRole, 403, 'You are not part of this game.');
    }
}
