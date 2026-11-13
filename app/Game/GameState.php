<?php

namespace App\Game;

/**
 * Immutable snapshot of a game in progress. Pure data — all behavior lives
 * in GameEngine. Mutate via with(), which returns a new instance.
 *
 * Phase A-D scope: the hex city, Location placement, Wanderer movement,
 * the Relic search/equip/drop loop, a Golem phase that resolves cards
 * right-to-left each round, and the 4 Infrastructure types (Monolith
 * Shard, Road, Walls, Fruit tree). Win/loss threshold logic exists
 * (outcome()), but nothing grants Prophecy yet — that all lives in Relic
 * and Location effects, which aren't executed. Only a hand-picked subset
 * of the 29 Golem cards and the ~109 Relics have real, executed behavior
 * — see GameEngine::resolveGolemCard for exactly which cards do what;
 * everything else is logged but not mechanically applied, same as Relic
 * *effects* (never executed at all).
 *
 * board["col,row"] = [
 *     'location_key' => string,
 *     'placed_round' => int,
 *     'relics' => list<string>,  // face-down pile still on this space
 *     'infrastructure' => list<'monolith_shard'|'road'|'walls'|'fruit_tree'>,
 * ]
 *
 * wanderers[roleKey] = [
 *     'cell' => [col, row]|null,      // null only before setup picks a start
 *     'hp' => int,                     // 1-15, 0 = exhausted (not dead)
 *     'actions_remaining' => int,      // only meaningful on that Wanderer's own turn
 *     'equipped' => [
 *         // each slot: null, a Relic key, or a status token sentinel
 *         // ('shame_token'|'scar_token') — rules.md: a token occupies a
 *         // slot of the Wanderer's choice (the Golem-pilot's choice in
 *         // adversary mode) and no Relic can go there while it remains.
 *         'body' => ?string, 'mind' => ?string,
 *         'hand_left' => ?string, 'hand_right' => ?string,
 *         'bag' => ?string,
 *     ],
 *     'holding' => list<string>,       // searched but not yet equipped or dropped
 *     'free_move_available' => bool,   // set after arriving on a Road — next move is free
 * ]
 *
 * infrastructurePool = ['monolith_shard' => int, 'road' => int, 'walls' => int, 'fruit_tree' => int]
 * — the Emperor's pool to place 2 from per turn. An intentionally fixed
 * supply (confirmed with the designer): starts at the full physical
 * component count (12/10/2/2) and is never replenished for the rest of
 * the game.
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
     * @param  array<string, array{location_key: string, placed_round: int, relics: list<string>, infrastructure: list<string>}>  $board
     * @param  list<string>  $locationDeck
     * @param  list<string>  $locationHand
     * @param  list<string>  $locationDiscard
     * @param  array<string, list<string>>  $relicDecks
     * @param  array<string, array<string, mixed>>  $wanderers
     * @param  array<string, mixed>  $golem
     * @param  list<string>  $glyphDeck
     * @param  array<string, int>  $infrastructurePool
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
        public array $infrastructurePool,
        public int $prophecy = 0,
        public int $infrastructurePlacedThisTurn = 0,
        public array $pendingSetupWanderers = [],
        public bool $pendingGolemPlacement = true,
        public array $wandererOrder = [],
        public ?int $activeWandererIndex = null,
    ) {}

    /**
     * rules.md's Game End thresholds, confirmed with the designer
     * 2026-11-12: 12 Prophecy = simple victory, 16 = elite victory.
     * Prophecy-*granting* isn't modeled yet (it all comes from Relic and
     * Location effects, which aren't executed), so this is always
     * 'defeat' today — the threshold logic is just ready for whenever
     * those land.
     */
    public function outcome(): string
    {
        return match (true) {
            $this->prophecy >= 16 => 'elite_victory',
            $this->prophecy >= 12 => 'simple_victory',
            default => 'defeat',
        };
    }

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
     * Cell keys with a Walls fixture — the Golem cannot enter these.
     *
     * @return list<string>
     */
    public function wallKeys(): array
    {
        return array_keys(array_filter(
            $this->board,
            fn (array $entry) => in_array('walls', $entry['infrastructure'], true)
        ));
    }

    /**
     * @param  array{location_key: string, placed_round: int, relics: list<string>, infrastructure: list<string>}  $entry
     * @return array<string, array{location_key: string, placed_round: int, relics: list<string>, infrastructure: list<string>}>
     */
    public function boardWith(string $cellKey, array $entry): array
    {
        $board = $this->board;
        $board[$cellKey] = $entry;

        return $board;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, array<string, mixed>>
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
            'infrastructure_pool' => $this->infrastructurePool,
            'infrastructure_placed_this_turn' => $this->infrastructurePlacedThisTurn,
            'prophecy' => $this->prophecy,
            'outcome' => $this->phase === 'finished' ? $this->outcome() : null,
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        $props = get_object_vars($this);

        return new self(...array_merge($props, $overrides));
    }
}
