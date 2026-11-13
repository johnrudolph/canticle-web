<?php

namespace App\Game;

/**
 * Immutable snapshot of a game in progress. Pure data — all behavior lives
 * in GameEngine. Mutate via with(), which returns a new instance.
 *
 * Phase A+B+C scope: the hex city, Location placement, Wanderer movement,
 * the Relic search/equip/drop loop, and a Golem phase that resolves cards
 * right-to-left each round. Infrastructure, Road networks, and scoring are
 * not modeled yet. Only a hand-picked subset of the 29 Golem cards and the
 * ~109 Relics have real, executed behavior — see GameEngine::resolveGolemCard
 * for exactly which cards do what; everything else is logged but not
 * mechanically applied, same as Relic *effects* (never executed at all).
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
 * built (see RelicDealer).
 *
 * golem = [
 *     'cell' => [col, row]|null,  // null only before setup places it
 *     'shame' => int,
 *     'glyphs' => list<string>,   // up to 4, FIFO — see GameEngine::addGlyph
 *     'draw_pile' => list<string>,
 *     'revealed' => list<string>, // up to 3, face up, resolved right-to-left
 *     'discard_pile' => list<string>,
 * ]
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
     * @param  array<string, mixed>  $golem
     * @param  list<string>  $glyphDeck
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
        public array $golem,
        public array $glyphDeck,
        public array $pendingSetupWanderers = [],
        public bool $pendingGolemPlacement = true,
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
     * Golem cards played this round — rules.md's Round track.
     */
    public function golemCardsThisRound(): int
    {
        return match (true) {
            $this->round <= 2 => 3,
            $this->round <= 4 => 4,
            $this->round <= 6 => 5,
            default => 6,
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
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public function golemWith(array $overrides): array
    {
        return [...$this->golem, ...$overrides];
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
            'golem' => [
                'cell' => $this->golem['cell'],
                'shame' => $this->golem['shame'],
                'glyphs' => $this->golem['glyphs'],
                'revealed' => $this->golem['revealed'],
                'draw_pile_count' => count($this->golem['draw_pile']),
            ],
            'pending_setup_wanderers' => $this->pendingSetupWanderers,
            'pending_golem_placement' => $this->pendingGolemPlacement,
            'wanderer_order' => $this->wandererOrder,
            'active_wanderer_role' => $this->activeWandererRole(),
            'actions_per_wanderer_turn' => $this->actionsPerWandererTurn(),
            'golem_cards_this_round' => $this->golemCardsThisRound(),
        ];
    }

    /**
     * Full internal state, including hidden information (deck/pile order)
     * that toArray() deliberately omits from the player-facing payload.
     *
     * @return array<string, mixed>
     */
    public function toDebugArray(): array
    {
        return [
            ...$this->toArray(),
            'location_deck' => $this->locationDeck,
            'relic_decks' => $this->relicDecks,
            'golem_draw_pile' => $this->golem['draw_pile'],
            'golem_discard_pile' => $this->golem['discard_pile'],
            'glyph_deck' => $this->glyphDeck,
        ];
    }

    public function with(array $overrides): self
    {
        $props = get_object_vars($this);

        return new self(...array_merge($props, $overrides));
    }
}
