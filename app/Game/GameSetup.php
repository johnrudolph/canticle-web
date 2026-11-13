<?php

namespace App\Game;

use App\Game\Catalog\LocationCatalog;

final class GameSetup
{
    private const STARTING_HAND_SIZE = 6;

    private const STARTING_HP = 15;

    public static function create(int $wandererCount): GameState
    {
        $deck = array_keys(LocationCatalog::all());
        shuffle($deck);

        $startingKeys = array_splice($deck, 0, 6);
        $startingCells = Board::startingCells();

        $board = [];
        foreach ($startingCells as $i => $cell) {
            $board[Board::key($cell)] = [
                'location_key' => $startingKeys[$i],
                'placed_round' => 1,
            ];
        }

        $hand = array_splice($deck, 0, self::STARTING_HAND_SIZE);

        $wandererRoles = RoleKeys::wanderers($wandererCount);
        $wanderers = [];
        foreach ($wandererRoles as $role) {
            $wanderers[$role] = ['cell' => null, 'hp' => self::STARTING_HP, 'actions_remaining' => 0];
        }

        return new GameState(
            wandererCount: $wandererCount,
            round: 1,
            phase: 'setup',
            board: $board,
            locationDeck: array_values($deck),
            locationHand: array_values($hand),
            locationDiscard: [],
            wanderers: $wanderers,
            pendingSetupWanderers: $wandererRoles,
        );
    }
}
