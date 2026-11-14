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
            golem: $data['golem'],
            glyphDeck: $data['glyph_deck'] ?? [],
            infrastructurePool: $data['infrastructure_pool'],
            prophecy: $data['prophecy'] ?? 0,
            infrastructurePlacedThisTurn: $data['infrastructure_placed_this_turn'] ?? 0,
            pendingSetupWanderers: $data['pending_setup_wanderers'] ?? [],
            pendingGolemPlacement: $data['pending_golem_placement'] ?? false,
            wandererOrder: $data['wanderer_order'] ?? [],
            activeWandererIndex: $data['active_wanderer_index'] ?? null,
            glyphDiscardPile: $data['glyph_discard_pile'] ?? [],
            golemSkipped: $data['golem_skipped'] ?? false,
            trashedRelics: $data['trashed_relics'] ?? [],
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
                'golem' => $state->golem,
                'glyph_deck' => $state->glyphDeck,
                'infrastructure_pool' => $state->infrastructurePool,
                'prophecy' => $state->prophecy,
                'infrastructure_placed_this_turn' => $state->infrastructurePlacedThisTurn,
                'pending_setup_wanderers' => $state->pendingSetupWanderers,
                'pending_golem_placement' => $state->pendingGolemPlacement,
                'wanderer_order' => $state->wandererOrder,
                'active_wanderer_index' => $state->activeWandererIndex,
                'glyph_discard_pile' => $state->glyphDiscardPile,
                'golem_skipped' => $state->golemSkipped,
                'trashed_relics' => $state->trashedRelics,
            ],
        ]);
    }
}
