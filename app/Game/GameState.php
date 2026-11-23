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
 * and Location effects, which aren't executed. All 27 distinct Golem
 * cards have real, executed behavior now (see GameEngine::resolveGolemCard);
 * only a hand-picked subset of the ~109 Relics do — everything else is
 * logged but not mechanically applied, same honesty pattern.
 *
 * board["col,row"] = [
 *     'location_key' => string,
 *     'placed_round' => int,
 *     'relics' => list<string>,  // face-down pile still on this space — rules.md: "search" draws blind from here
 *     'relics_face_up' => list<string>,  // dropped (or displaced-on-equip) Relics sitting face-up — rules.md: "Dropping a relic leaves it face-up on your current space," pickupable by anyone, any time, by name, with no search roll
 *     'infrastructure' => list<'monolith_shard'|'road'|'walls'|'fruit_tree'>,
 *     'tucked' => list<string>,  // Bazaar/Cathedral/Golem sanctuary/Defenders' Guild/House of Cryptids tuck Relic or Golem card keys here, per their own back_text
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
 *         // mind_2/mind_3 only ever equippable while 'mind' itself holds
 *         // 'an-expansive-mind' — "You can add two more Mind cards to
 *         // this slot" (GameEngine::equipRelic enforces this gate).
 *         'mind_2' => ?string, 'mind_3' => ?string,
 *     ],
 *     'holding' => list<string>,       // searched but not yet equipped or dropped
 *     'move_blocked_this_turn' => bool, // Incarceration — cleared when this Wanderer's next turn starts
 *     'has_moved_this_turn' => bool, // Road — "it always costs 1 action to move" for a Wanderer's FIRST move of their own turn, even if they're already standing on a Road; only moves after that first one can be free for stepping off a Road space. Cleared when this Wanderer's next turn starts.
 *     'tucked_cards' => array<string, list<string>>, // keyed by the equipped Relic (Defanging tool/Golem log) tracking its own progress; dropping that Relic loses the progress (rules.md)
 *     'was_ever_exhausted' => bool, // set once hp hits 0 for real (not saved by Cabalist wraps) — the Catacombs Location requires this to enter
 *     'golem_insight_used_this_round' => bool, // Golem insight — "once per round" — cleared at the start of each new round
 *     'laborers_jacket_used_this_round' => bool, // Laborer's Jacket — modeled as usable once per round rather than strictly "at the start of the Wanderer phase" (no clean architectural seam for that exact timing) — cleared at the start of each new round
 *     'pending_bonus_actions' => int, // Paradoxical Glyph — granted mid-Golem-phase, outside any Wanderer's actual turn, so it can't just add to actions_remaining (the next turn-start would overwrite it); banked here instead and added on top of the fresh per-turn amount when this Wanderer's next turn actually starts, then reset to 0
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
 *     'glyphs' => list<string>,   // up to 3 active, face up, FIFO — see GameEngine::addGlyphOfType
 *     'draw_pile' => list<array{key: string, tapped: bool}>,
 *     'revealed' => list<array{key: string, tapped: bool}>, // up to 3, face up, resolved right-to-left
 *     'discard_pile' => list<string>, // tapped state doesn't matter once discarded
 *     'armed_attack_reduction' => int, // Poison Charm — queued for the NEXT 👊 card, promoted to active_attack_reduction when that card starts resolving
 *     'active_attack_reduction' => int, // Poison Charm — applies to every hit dealt by the 👊 card currently resolving; cleared once that card finishes
 * ]
 *
 * glyphDiscardPile = list<string> — Glyphs bumped off the 4 active slots
 * (FIFO overflow) land here rather than vanishing; Past troubles is the
 * only thing that currently reads it (shuffles it back in with the deck
 * and the active Glyphs).
 *
 * A tapped card (confirmed via chat 2026-11-13: flipped face-down; when
 * resolution reaches it, it does nothing) is set by specific Relics that
 * reach into the Golem's own draw pile — see GameEngine::tapGolemDrawPile.
 */
final readonly class GameState
{
    /**
     * @param  array<string, array{location_key: string, placed_round: int, relics: list<string>, relics_face_up: list<string>, infrastructure: list<string>, tucked: list<string>}>  $board
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
     * @param  list<string>  $glyphDiscardPile
     * @param  list<string>  $trashedRelics
     * @param  list<array{cell: array{0: int, 1: int}, type: string}>  $infrastructurePlacedThisTurnLog
     * @param  array{0: int, 1: int}|null  $locationJustBuiltCell
     * @param  array{type: string, card_key: string, awaiting: list<string>, remaining_cards: int}|null  $pendingGolemCardChoice
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
        public array $glyphDiscardPile = [],
        public bool $golemSkipped = false, // Poof: "Skip the Golem phase for the rest of the game."
        public array $trashedRelics = [], // every Relic ever trashed (not dropped) — Re-creation draws from this
        public array $infrastructurePlacedThisTurnLog = [], // undo_infrastructure_placement pops from the end of this; cleared whenever infrastructurePlacedThisTurn itself resets (new round)
        public ?array $locationJustBuiltCell = null, // Perplexing Glyph — the cell of whichever Location the Emperor most recently played; overwritten every time a new one is played, never otherwise cleared (a Wanderer may use it at their leisure, not strictly "immediately")
        public bool $parallelUsedThisRound = false, // Parallel Glyph — "once per round," shared across all Wanderers (not per-Wanderer); cleared at the start of each new round
        public ?string $pendingInvigoration = null, // Invigoration's "you may lose 7 Health..." choice can't be supplied in the same move that triggered the search (the Wanderer doesn't know they found it yet) — this holds who must decide via a separate resolve_invigoration move before anything else happens
        public ?string $pendingRiftInTime = null, // Rift in time's "choose a face-up Glyph to replace" choice — same can't-supply-it-in-the-same-move problem as Invigoration; resolved via resolve_rift_in_time
        public ?string $pendingEpiphany = null, // Epiphany's "choose another Location to search as if you were there" choice — same problem again; resolved via resolve_epiphany
        public ?string $pendingGolemLog = null, // Golem log's "look at the top 3 cards, then tuck one" choice — same problem again; resolved via resolve_golem_log
        public ?array $pendingGolemCardChoice = null, // Fire storm/Roar/Sacrifice of Possessions force each affected Wanderer to choose something mid-Golem-phase; {type: 'trash_relic'|'move_direction', card_key: string, awaiting: list<string>, remaining_cards: int} — pauses the whole Golem phase (and the round) until everyone in `awaiting` has responded via resolve_golem_card_choice
    ) {}

    /**
     * rules.md's Game End thresholds, confirmed with the designer
     * 2026-11-12: 12 Prophecy = simple victory, 16 = elite victory.
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
     * Golem cards played this round — rules.md's Round track, reduced
     * by whichever card-count Glyphs are active:
     *
     * - Penitent: "The Golem plays 1 fewer card per Shame it has"
     *   (counting Accused's virtual +1 too — it inflates every
     *   mechanic that reads Shame as a quantity).
     * - Lilted: "...for each 🎶 equipped by Wanderers" (lute, pipe,
     *   tabor-drum, counted across every Wanderer's equipped slots).
     * - Empathetic: "...for each Wanderer that is on its space."
     *
     * Never below 0. All three glyph checks only need data already on
     * this object (glyph keys, shame, equipped/cell), so this stays a
     * pure derived getter rather than something GameEngine computes.
     */
    public function golemCardsThisRound(): int
    {
        $count = match (true) {
            $this->round <= 2 => 3,
            $this->round <= 4 => 4,
            $this->round <= 6 => 5,
            default => 6,
        };

        $glyphs = $this->golem['glyphs'];

        if (in_array('penitent', $glyphs, true)) {
            $shame = $this->golem['shame'] + (in_array('accused', $glyphs, true) ? 1 : 0);
            $count -= $shame;
        }

        if (in_array('lilted', $glyphs, true)) {
            $instruments = ['lute', 'pipe', 'tabor-drum'];
            foreach ($this->wanderers as $wanderer) {
                $count -= count(array_intersect($instruments, array_values($wanderer['equipped'])));
            }
        }

        if (in_array('empathetic', $glyphs, true) && $this->golem['cell'] !== null) {
            $golemKey = Board::key($this->golem['cell']);
            foreach ($this->wanderers as $wanderer) {
                if ($wanderer['cell'] !== null && Board::key($wanderer['cell']) === $golemKey) {
                    $count--;
                }
            }
        }

        return max(0, (int) $count);
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
     * @param  array{location_key: string, placed_round: int, relics: list<string>, relics_face_up: list<string>, infrastructure: list<string>, tucked: list<string>}  $entry
     * @return array<string, array{location_key: string, placed_round: int, relics: list<string>, relics_face_up: list<string>, infrastructure: list<string>, tucked: list<string>}>
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
            'location_hand_count' => count($this->locationHand),
            'location_discard' => $this->locationDiscard,
            'wanderers' => $this->wanderers,
            'golem' => [
                'cell' => $this->golem['cell'],
                'shame' => $this->golem['shame'],
                'glyphs' => $this->golem['glyphs'],
                'revealed' => $this->golem['revealed'],
                'draw_pile_count' => count($this->golem['draw_pile']),
                'discard_pile_count' => count($this->golem['discard_pile']),
            ],
            'pending_setup_wanderers' => $this->pendingSetupWanderers,
            'pending_golem_placement' => $this->pendingGolemPlacement,
            'wanderer_order' => $this->wandererOrder,
            'active_wanderer_role' => $this->activeWandererRole(),
            'actions_per_wanderer_turn' => $this->actionsPerWandererTurn(),
            'golem_cards_this_round' => $this->golemCardsThisRound(),
            'infrastructure_pool' => $this->infrastructurePool,
            'infrastructure_placed_this_turn' => $this->infrastructurePlacedThisTurn,
            'infrastructure_placed_this_turn_log' => $this->infrastructurePlacedThisTurnLog,
            'prophecy' => $this->prophecy,
            'golem_skipped' => $this->golemSkipped,
            'pending_invigoration' => $this->pendingInvigoration,
            'pending_rift_in_time' => $this->pendingRiftInTime,
            'pending_epiphany' => $this->pendingEpiphany,
            'pending_golem_log' => $this->pendingGolemLog,
            'pending_golem_card_choice' => $this->pendingGolemCardChoice === null ? null : [
                'type' => $this->pendingGolemCardChoice['type'],
                'card_key' => $this->pendingGolemCardChoice['card_key'],
                'awaiting' => $this->pendingGolemCardChoice['awaiting'],
            ],
            'location_just_built_cell' => $this->locationJustBuiltCell,
            'parallel_used_this_round' => $this->parallelUsedThisRound,
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
            'glyph_discard_pile' => $this->glyphDiscardPile,
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
