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

    private const EQUIP_SLOTS = ['body', 'mind', 'hand_left', 'hand_right', 'bag'];

    private const GOLEM_REVEALED_SIZE = 3;

    public static function create(int $wandererCount): GameState
    {
        $deck = array_keys(LocationCatalog::all());
        shuffle($deck);

        $startingKeys = array_splice($deck, 0, 6);
        $startingCells = Board::startingCells();

        $relicDecks = self::buildRelicDecks();

        $board = [];
        foreach ($startingCells as $i => $cell) {
            $location = LocationCatalog::find($startingKeys[$i]);
            [$relics, $relicDecks] = RelicDealer::dealForLocation($relicDecks, $location, $board);

            $board[Board::key($cell)] = [
                'location_key' => $startingKeys[$i],
                'placed_round' => 1,
                'relics' => $relics,
                'infrastructure' => [],
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
                'free_move_available' => false,
                'move_blocked_this_turn' => false,
                'tucked_cards' => [],
                'was_ever_exhausted' => false,
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
            ],
            glyphDeck: $glyphDeck,
            infrastructurePool: [
                'monolith_shard' => 12,
                'road' => 10,
                'walls' => 2,
                'fruit_tree' => 2,
            ],
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
