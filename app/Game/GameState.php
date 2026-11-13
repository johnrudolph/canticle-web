<?php

namespace App\Game;

/**
 * Immutable snapshot of a game in progress. Pure data — all behavior lives
 * in GameEngine. Mutate via with(), which returns a new instance.
 *
 * Phase A scope only: the hex city, Location placement, and Wanderer
 * movement. Relics, Infrastructure, Golem combat, and scoring are not
 * modeled yet — the Golem phase is a no-op pass-through (see GameEngine)
 * and `phase` never becomes 'golem' as a result.
 *
 * board["col,row"] = ['location_key' => string, 'placed_round' => int]
 *
 * wanderers[roleKey] = [
 *     'cell' => [col, row]|null,   // null only before setup picks a start
 *     'hp' => int,                  // 1-15, 0 = exhausted (not dead)
 *     'actions_remaining' => int,   // only meaningful on that Wanderer's own turn
 * ]
 */
final readonly class GameState
{
    /**
     * @param  array<string, array{location_key: string, placed_round: int}>  $board
     * @param  list<string>  $locationDeck
     * @param  list<string>  $locationHand
     * @param  list<string>  $locationDiscard
     * @param  array<string, array{cell: ?array{0: int, 1: int}, hp: int, actions_remaining: int}>  $wanderers
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
     * @return array<string, array{location_key: string, placed_round: int}>
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
        return [...$this->toArray(), 'location_deck' => $this->locationDeck];
    }

    public function with(array $overrides): self
    {
        $props = get_object_vars($this);

        return new self(...array_merge($props, $overrides));
    }
}
