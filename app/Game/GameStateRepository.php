<?php

namespace App\Game;

use App\Models\Game;

final class GameStateRepository
{
    public static function load(Game $game): GameState
    {
        $data = $game->state;

        return new GameState(
            wandererCount: $data['wanderer_count'],
            round: $data['round'],
            phase: $data['phase'],
            board: $data['board'],
            locationDeck: $data['location_deck'],
            locationHand: $data['location_hand'],
            locationDiscard: $data['location_discard'],
            relicDecks: $data['relic_decks'] ?? [],
            wanderers: $data['wanderers'],
            pendingSetupWanderers: $data['pending_setup_wanderers'] ?? [],
            wandererOrder: $data['wanderer_order'] ?? [],
            activeWandererIndex: $data['active_wanderer_index'] ?? null,
        );
    }

    public static function persist(Game $game, GameState $state): void
    {
        $game->update([
            'round' => $state->round,
            'phase' => $state->phase,
            'state' => [
                'wanderer_count' => $state->wandererCount,
                'round' => $state->round,
                'phase' => $state->phase,
                'board' => $state->board,
                'location_deck' => $state->locationDeck,
                'location_hand' => $state->locationHand,
                'location_discard' => $state->locationDiscard,
                'relic_decks' => $state->relicDecks,
                'wanderers' => $state->wanderers,
                'pending_setup_wanderers' => $state->pendingSetupWanderers,
                'wanderer_order' => $state->wandererOrder,
                'active_wanderer_index' => $state->activeWandererIndex,
            ],
        ]);
    }
}
