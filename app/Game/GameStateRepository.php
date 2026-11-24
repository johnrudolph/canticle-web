<?php

namespace App\Game;

use App\Models\Game;

final class GameStateRepository
{
    /**
     * New fields get added to board/wanderer/golem sub-structures over
     * time as new mechanics land — but a game that started playing
     * before that commit deployed has its JSON blob persisted without
     * them, forever, since nothing ever re-writes an old row's shape.
     * Confirmed in production (game CYTGGM): equipping over an already-
     * equipped slot spreads board[cell]['relics_face_up'] — added after
     * that game started — which doesn't exist on its persisted board,
     * so the missing key reads as null and spreading it is a fatal
     * TypeError. Every field added after initial launch needs an
     * explicit default here, not just in GameSetup (which only ever
     * runs for brand-new games).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        $board = $data['board'];
        foreach ($board as $key => $entry) {
            $board[$key] = [
                ...$entry,
                'relics_face_up' => $entry['relics_face_up'] ?? [],
                'tucked' => $entry['tucked'] ?? [],
                'infrastructure' => $entry['infrastructure'] ?? [],
            ];
        }

        $wanderers = $data['wanderers'];
        foreach ($wanderers as $role => $wanderer) {
            $wanderers[$role] = [
                ...$wanderer,
                'move_blocked_this_turn' => $wanderer['move_blocked_this_turn'] ?? false,
                'has_moved_this_turn' => $wanderer['has_moved_this_turn'] ?? false,
                'tucked_cards' => $wanderer['tucked_cards'] ?? [],
                'was_ever_exhausted' => $wanderer['was_ever_exhausted'] ?? false,
                'golem_insight_used_this_round' => $wanderer['golem_insight_used_this_round'] ?? false,
                'laborers_jacket_used_this_round' => $wanderer['laborers_jacket_used_this_round'] ?? false,
                'pending_bonus_actions' => $wanderer['pending_bonus_actions'] ?? 0,
                'holding' => $wanderer['holding'] ?? [],
                // Shame Zone (rules.md 2026-11-24 revision) — before
                // this, Shame lived in an equip slot as a 'shame_token'
                // string, same as Burn still does today. A game that
                // started before this shipped has no 'shame' key at
                // all; back-fill it by counting however many
                // 'shame_token' entries its persisted equipped array
                // still has, so nobody's existing Shame silently
                // vanishes on the next load — and free those slots back
                // up below, since 'shame_token' is no longer a
                // recognized value there at all.
                'shame' => $wanderer['shame'] ?? count(array_filter(
                    $wanderer['equipped'],
                    fn (mixed $v) => $v === 'shame_token',
                )),
                'equipped' => [
                    ...array_map(
                        fn (mixed $v) => $v === 'shame_token' ? null : $v,
                        $wanderer['equipped'],
                    ),
                    'mind_2' => $wanderer['equipped']['mind_2'] ?? null,
                    'mind_3' => $wanderer['equipped']['mind_3'] ?? null,
                ],
            ];
        }

        $golem = [
            ...$data['golem'],
            'armed_attack_reduction' => $data['golem']['armed_attack_reduction'] ?? 0,
            'active_attack_reduction' => $data['golem']['active_attack_reduction'] ?? 0,
        ];

        return [...$data, 'board' => $board, 'wanderers' => $wanderers, 'golem' => $golem];
    }

    public static function load(Game $game): GameState
    {
        $data = self::normalize($game->state);

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
            infrastructurePlacedThisTurnLog: $data['infrastructure_placed_this_turn_log'] ?? [],
            locationJustBuiltCell: $data['location_just_built_cell'] ?? null,
            parallelUsedThisRound: $data['parallel_used_this_round'] ?? false,
            pendingInvigoration: $data['pending_invigoration'] ?? null,
            pendingRiftInTime: $data['pending_rift_in_time'] ?? null,
            pendingEpiphany: $data['pending_epiphany'] ?? null,
            pendingGolemLog: $data['pending_golem_log'] ?? null,
            pendingGolemCardChoice: $data['pending_golem_card_choice_full'] ?? null,
            pendingStatusTokenChoices: $data['pending_status_token_choices'] ?? [],
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
                'infrastructure_placed_this_turn_log' => $state->infrastructurePlacedThisTurnLog,
                'location_just_built_cell' => $state->locationJustBuiltCell,
                'parallel_used_this_round' => $state->parallelUsedThisRound,
                'pending_invigoration' => $state->pendingInvigoration,
                'pending_rift_in_time' => $state->pendingRiftInTime,
                'pending_epiphany' => $state->pendingEpiphany,
                'pending_golem_log' => $state->pendingGolemLog,
                'pending_golem_card_choice_full' => $state->pendingGolemCardChoice,
                'pending_status_token_choices' => $state->pendingStatusTokenChoices,
            ],
        ]);
    }
}
