<?php

namespace App\Game;

/**
 * Immutable snapshot of a game in progress. Pure data — all behavior lives
 * in GameEngine. Mutate via with(), which returns a new instance.
 *
 * Phase A+B scope: the hex city, Location placement, Wanderer movement,
 * and the Relic search/equip/drop loop. Infrastructure, Road networks,
 * Golem combat, and scoring are not modeled yet — the Golem phase is a
 * no-op pass-through (see GameEngine) and `phase` never becomes 'golem'.
 * Relic *effects* (the free-text `effect` field) are not executed — only
 * dealing, searching, equipping, and dropping them.
 *
 * board["col,row"] = [
 *     'location_key' => string,
 *     'placed_round' => int,
 *     'relics' => list<string>,  // face-down pile still on this space
 * ]
 *
 * wanderers[roleKey] = [
 *     'cell' => [col, row]|null,      // null only before setup picks a start
 *     'hp' => int,                     // 1-15, 0 = exhausted (not dead)
 *     'actions_remaining' => int,      // only meaningful on that Wanderer's own turn
 *     'equipped' => [
 *         'body' => ?string, 'mind' => ?string,
 *         'hand_left' => ?string, 'hand_right' => ?string,
 *         'bag' => ?string,
 *     ],
 *     'holding' => list<string>,       // searched but not yet equipped or dropped
 * ]
 *
 * relicDecks[branchEmoji] = list<string> — shuffled, shared across every
 * Location of that branch for the whole game; consumed as Locations are
 * built (see GameEngine::dealRelics).
 */
final readonly class GameState
{
    /**
     * @param  array<string, array{location_key: string, placed_round: int, relics: list<string>}>  $board
     * @param  list<string>  $locationDeck
     * @param  list<string>  $locationHand
     * @param  list<string>  $locationDiscard
     * @param  array<string, list<string>>  $relicDecks
     * @param  array<string, array<string, mixed>>  $wanderers
     * @param  list<string>  $pendingSetupWanderers
     * @param  list<string>  $wandererOrder
     */
    public function __construct(
        public int $wandererCount,
        public int $round,
        public string $phase, // setup|emperor|wanderer|finished
        public array $board,
        public array $locationDeck,
        public array $locationHand,
        public array $locationDiscard,
        public array $relicDecks,
        public array $wanderers,
        public array $pendingSetupWanderers = [],
        public array $wandererOrder = [],
        public ?int $activeWandererIndex = null,
    ) {}

    public function activeWandererRole(): ?string
    {
        if ($this->activeWandererIndex === null) {
            return null;
        }

        return $this->wandererOrder[$this->activeWandererIndex] ?? null;
    }

    /**
     * Actions a Wanderer gets on their own turn — fixed by how many
     * Wanderers are in this game (never a function of anything else).
     */
    public function actionsPerWandererTurn(): int
    {
        return match ($this->wandererCount) {
            2 => 3,
            3 => 2,
            default => throw new \LogicException("Unsupported wanderer count: {$this->wandererCount}"),
        };
    }

    /**
     * @return array<string, array{location_key: string, placed_round: int, relics: list<string>}>
     */
    public function boardWith(string $cellKey, array $entry): array
    {
        $board = $this->board;
        $board[$cellKey] = $entry;

        return $board;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function wanderersWith(string $role, array $overrides): array
    {
        $wanderers = $this->wanderers;
        $wanderers[$role] = [...$wanderers[$role], ...$overrides];

        return $wanderers;
    }

    /**
     * @return array<string, list<string>>
     */
    public function relicDecksWith(string $branchEmoji, array $deck): array
    {
        $decks = $this->relicDecks;
        $decks[$branchEmoji] = $deck;

        return $decks;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'wanderer_count' => $this->wandererCount,
            'round' => $this->round,
            'phase' => $this->phase,
            'board' => $this->board,
            'location_deck_count' => count($this->locationDeck),
            'location_hand' => $this->locationHand,
            'location_discard' => $this->locationDiscard,
            'wanderers' => $this->wanderers,
            'pending_setup_wanderers' => $this->pendingSetupWanderers,
            'wanderer_order' => $this->wandererOrder,
            'active_wanderer_role' => $this->activeWandererRole(),
            'actions_per_wanderer_turn' => $this->actionsPerWandererTurn(),
        ];
    }

    /**
     * Full internal state, including hidden information (deck order) that
     * toArray() deliberately omits from the player-facing payload.
     *
     * @return array<string, mixed>
     */
    public function toDebugArray(): array
    {
        return [
            ...$this->toArray(),
            'location_deck' => $this->locationDeck,
            'relic_decks' => $this->relicDecks,
        ];
    }

    public function with(array $overrides): self
    {
        $props = get_object_vars($this);

        return new self(...array_merge($props, $overrides));
    }
}
