<?php

namespace App\Game;

use App\Game\Catalog\GlyphCatalog;
use App\Game\Catalog\GolemCardCatalog;
use App\Game\Catalog\LocationCatalog;
use App\Game\Catalog\RelicCatalog;

final class GameSetup
{
    // Matches GameEngine::EMPEROR_HAND_SIZE — the hand is always 5.
    private const STARTING_HAND_SIZE = 5;

    private const STARTING_HP = 15;

    // mind_2/mind_3 only ever usable while An expansive mind occupies
    // 'mind' itself (see GameEngine::equipRelic) — "You can add two more
    // Mind cards to this slot."
    private const EQUIP_SLOTS = ['body', 'mind', 'hand_left', 'hand_right', 'bag', 'mind_2', 'mind_3'];

    private const GOLEM_REVEALED_SIZE = 3;

    public static function create(int $wandererCount): GameState
    {
        $deck = array_keys(LocationCatalog::all());
        shuffle($deck);

        // The 6 starting Locations are placed on the board, not built —
        // the sheet's `is_starter` column is the designer's explicit
        // curated list of which Locations may do that (in practice,
        // exactly the cost-free ones, since a build cost is meaningless
        // for a space that's simply there from the start). Locations
        // not marked as starters stay in the deck to be built normally.
        $freeKeys = array_values(array_filter(
            $deck,
            fn (string $key) => LocationCatalog::find($key)?->isStarter === true,
        ));
        $startingKeys = array_splice($freeKeys, 0, 6);
        $deck = array_values(array_diff($deck, $startingKeys));
        $startingCells = Board::startingCells();

        $relicDecks = self::buildRelicDecks();

        $infrastructurePool = [
            'monolith_shard' => 12,
            'road' => 10,
            'walls' => 2,
            'fruit_tree' => 3,
        ];

        $board = [];
        foreach ($startingCells as $i => $cell) {
            $location = LocationCatalog::find($startingKeys[$i]);
            [$relics, $relicDecks] = RelicDealer::dealForLocation($relicDecks, $location, $board);

            // Fixture outlines: a Location marked has_shard/has_tree on
            // the sheet starts with that fixture already on its space,
            // drawn from the same finite pool the Emperor places from.
            $infrastructure = [];
            if ($location?->hasShard && $infrastructurePool['monolith_shard'] > 0) {
                $infrastructure[] = 'monolith_shard';
                $infrastructurePool['monolith_shard']--;
            }
            if ($location?->hasTree && $infrastructurePool['fruit_tree'] > 0) {
                $infrastructure[] = 'fruit_tree';
                $infrastructurePool['fruit_tree']--;
            }

            $board[Board::key($cell)] = [
                'location_key' => $startingKeys[$i],
                'placed_round' => 1,
                'relics' => $relics,
                'relics_face_up' => [],
                'infrastructure' => $infrastructure,
                'tucked' => [],
            ];
        }

        $hand = array_splice($deck, 0, self::STARTING_HAND_SIZE);

        $wandererRoles = RoleKeys::wanderers($wandererCount);
        $wanderers = [];
        foreach ($wandererRoles as $role) {
            $wanderers[$role] = [
                'cell' => null,
                'hp' => self::STARTING_HP,
                'actions_remaining' => 0,
                'equipped' => array_fill_keys(self::EQUIP_SLOTS, null),
                'holding' => [],
                'move_blocked_this_turn' => false,
                'tucked_cards' => [],
                'was_ever_exhausted' => false,
                'golem_insight_used_this_round' => false,
                'laborers_jacket_used_this_round' => false,
                'pending_bonus_actions' => 0,
            ];
        }

        $golemDeck = array_keys(GolemCardCatalog::all());
        shuffle($golemDeck);
        $revealed = array_map(
            fn (string $key) => ['key' => $key, 'tapped' => false],
            array_splice($golemDeck, 0, self::GOLEM_REVEALED_SIZE)
        );
        $golemDeck = array_map(fn (string $key) => ['key' => $key, 'tapped' => false], $golemDeck);

        $glyphDeck = array_keys(GlyphCatalog::all());
        shuffle($glyphDeck);

        return new GameState(
            wandererCount: $wandererCount,
            round: 1,
            phase: 'setup',
            board: $board,
            locationDeck: $deck,
            locationHand: $hand,
            locationDiscard: [],
            relicDecks: $relicDecks,
            wanderers: $wanderers,
            golem: [
                'cell' => null,
                'shame' => 0,
                'glyphs' => [],
                'draw_pile' => $golemDeck,
                'revealed' => $revealed,
                'discard_pile' => [],
                'armed_attack_reduction' => 0,
                'active_attack_reduction' => 0,
            ],
            glyphDeck: $glyphDeck,
            infrastructurePool: $infrastructurePool,
            pendingSetupWanderers: $wandererRoles,
            pendingGolemPlacement: true,
        );
    }

    /**
     * One shuffled deck per branch that actually has Relics — Agriculture,
     * Commerce, and Engineering have none (confirmed against key.json).
     *
     * @return array<string, list<string>>
     */
    private static function buildRelicDecks(): array
    {
        $decks = [];

        foreach (RelicCatalog::all() as $relic) {
            $decks[$relic->symbol][] = $relic->key;
        }

        foreach ($decks as $branch => $keys) {
            shuffle($keys);
            $decks[$branch] = $keys;
        }

        return $decks;
    }
}
