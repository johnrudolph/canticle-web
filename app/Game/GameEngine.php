<?php

namespace App\Game;

use App\Game\Catalog\GlyphCatalog;
use App\Game\Catalog\GolemCardCatalog;
use App\Game\Catalog\LocationCatalog;
use App\Game\Catalog\RelicCatalog;

/**
 * Pure state transition function: (state, actor role, move) -> (new state,
 * events). Never touches the database — GameStateRepository and EventLog
 * handle persistence, the controller handles auth.
 *
 * Phase A-D scope: setup (choose starting cells for Wanderers and the
 * Golem), Emperor phase (play a Location — dealing its Relics automatically
 * — place up to 2 Infrastructure, discard, redraw), Wanderer phase (move,
 * search, equip/drop/pickup Relics), and a Golem phase that actually
 * resolves cards — see resolveGolemCard() for exactly which of the 29
 * cards have real behavior vs. are logged-but-not-executed. Relic
 * *effects* are never executed, only dealing/searching/equipping them.
 */
final class GameEngine
{
    private const ROUND_LIMIT = 8;

    private const GOLEM_REVEALED_SIZE = 3;

    private const GOLEM_MAX_GLYPHS = 3;

    // rules.md's Emperor phase step 5 — also the starting hand size
    // (GameSetup), so the hand is always exactly 5 between turns.
    private const EMPEROR_HAND_SIZE = 5;

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    public static function apply(GameState $state, string $actorRole, array $move): array
    {
        // Fire storm/Roar/Sacrifice of Possessions freeze the entire
        // game (not just one Wanderer's turn, unlike every other
        // pendingXxx field) the moment they force a choice — the round
        // itself hasn't finished resolving, so nothing else is safe to
        // do until every awaited Wanderer has responded.
        if ($state->pendingGolemCardChoice !== null && ($move['type'] ?? null) !== 'resolve_golem_card_choice') {
            throw new InvalidMoveException('The Golem\'s card is waiting on a choice before anything else can happen.');
        }

        // See applyBurn()'s own doc comment for why this is a
        // separate, simpler gate than pendingGolemCardChoice's — it
        // doesn't need to pause the Golem phase mid-resolution, only
        // block whatever move would otherwise come after this one.
        if ($state->pendingStatusTokenChoices !== [] && ($move['type'] ?? null) !== 'resolve_status_token_choice') {
            throw new InvalidMoveException('A Burn token slot choice is waiting before anything else can happen.');
        }

        [$state, $events] = match ($move['type'] ?? null) {
            'choose_starting_cell' => self::chooseStartingCell($state, $actorRole, $move),
            'choose_golem_cell' => self::chooseGolemCell($state, $actorRole, $move),
            'play_location' => self::playLocation($state, $actorRole, $move),
            'place_infrastructure' => self::placeInfrastructure($state, $actorRole, $move),
            'undo_infrastructure_placement' => self::undoInfrastructurePlacement($state, $actorRole),
            'end_emperor_phase' => self::endEmperorPhase($state, $actorRole, $move),
            'move_wanderer' => self::moveWanderer($state, $actorRole, $move),
            'search_location' => self::searchLocation($state, $actorRole, $move),
            'equip_relic' => self::equipRelic($state, $actorRole, $move),
            'drop_relic' => self::dropRelic($state, $actorRole, $move),
            'pickup_relic' => self::pickupRelic($state, $actorRole, $move),
            'use_relic' => self::useRelic($state, $actorRole, $move),
            'peek_location_deck' => self::peekLocationDeck($state, $actorRole, $move),
            'rest' => self::rest($state, $actorRole),
            'tuck_relic_at_bazaar' => self::tuckRelicAtBazaar($state, $actorRole, $move),
            'tuck_relic_at_cathedral' => self::tuckRelicAtCathedral($state, $actorRole, $move),
            'tuck_relic_at_garbage_barricade' => self::tuckRelicAtGarbageBarricade($state, $actorRole, $move),
            'cleanse_at_purification_pools' => self::cleanseAtPurificationPools($state, $actorRole),
            'magnetic_mail_move' => self::magneticMailMove($state, $actorRole, $move),
            'telekinesis_move' => self::telekinesisMove($state, $actorRole, $move),
            'laborers_jacket_move_allies' => self::laborersJacketMoveAllies($state, $actorRole, $move),
            'trash_relic_for_health' => self::trashRelicForHealth($state, $actorRole, $move),
            'neighborly_assist' => self::neighborlyAssist($state, $actorRole, $move),
            'move_to_new_location' => self::moveToNewLocation($state, $actorRole),
            'skip_golem_card' => self::skipGolemCard($state, $actorRole),
            'resolve_invigoration' => self::resolveInvigoration($state, $actorRole, $move),
            'resolve_rift_in_time' => self::resolveRiftInTime($state, $actorRole, $move),
            'resolve_epiphany' => self::resolveEpiphany($state, $actorRole, $move),
            'resolve_golem_log' => self::resolveGolemLog($state, $actorRole, $move),
            'resolve_golem_card_choice' => self::resolveGolemCardChoice($state, $actorRole, $move),
            'resolve_status_token_choice' => self::resolveStatusTokenChoice($state, $actorRole, $move),
            'end_wanderer_turn' => self::endWandererTurn($state, $actorRole),
            default => throw new InvalidMoveException('Unknown move type.'),
        };

        [$state, $chapelEvents] = self::checkFragileChapelDestruction($state);

        return [$state, [...$events, ...$chapelEvents]];
    }

    /**
     * Fragile Chapel: "Ongoing: ... If the Golem is ever on or
     * adjacent to this space, trash this, and move all Wanderers here
     * to adjacent spaces they choose, and set them to 0HP." Checked
     * once at the end of every apply() call rather than at each of the
     * half-dozen individual call sites that can move the Golem — by
     * the time a move() call fully settles, the Golem's position is
     * final for this transition regardless of how it got there
     * (a card's own movement, Golem whistle, a forced retreat, or
     * several cards cascading through one Golem phase).
     *
     * Simplification: "adjacent spaces they choose" has no real
     * interactive choice built — same category as Roar's own
     * non-paused fallback (self::golemRoar's $allowPause = false
     * branch), each displaced Wanderer is sent to whichever occupied
     * neighbor is farthest from the Golem (ties broken by iteration
     * order), or left in place if boxed in.
     *
     * The Location's identity is cleared (location_key set to '', its
     * Relics/tucked piles emptied) rather than the board entry being
     * unset entirely. Unsetting it would match how a not-yet-built
     * cell has no board entry at all (GameSetup::create()/
     * playLocation()), but everything that reads the current Golem or
     * Wanderer cell indexes straight into $state->board[cellKey] with
     * no isset guard — and rules.md never actually moves the Golem off
     * this space, only the Wanderers, so the Golem can legitimately
     * still be standing exactly here afterward. Keeping the entry (with
     * an empty location_key, same "no Location here" reading every
     * other check already gives LocationCatalog::find('')) avoids that
     * risk entirely. One side effect: unlike a genuinely unbuilt cell,
     * this space can't be built over again (playLocation() rejects any
     * cellKey already present in $state->board) — not addressed by
     * rules.md either way, so left as the conservative default.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function checkFragileChapelDestruction(GameState $state): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $chapelKey = null;
        foreach ($state->board as $key => $entry) {
            if ($entry['location_key'] === 'fragile-chapel') {
                $chapelKey = $key;
                break;
            }
        }

        if ($chapelKey === null) {
            return [$state, []];
        }

        $golemCellKey = Board::key($golemCell);
        $adjacentToChapel = array_map(Board::key(...), Board::occupiedNeighbors(Board::fromKey($chapelKey), $state->board));

        if ($golemCellKey !== $chapelKey && ! in_array($golemCellKey, $adjacentToChapel, true)) {
            return [$state, []];
        }

        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) !== $chapelKey) {
                continue;
            }

            $best = null;
            $bestDistance = -1;

            foreach (Board::occupiedNeighbors($cell, $state->board) as $option) {
                $distance = Board::distance($golemCell, $option, $state->board) ?? 0;

                if ($distance > $bestDistance) {
                    $best = $option;
                    $bestDistance = $distance;
                }
            }

            if ($best !== null) {
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $best])]);
                $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $best, 'reason' => 'fragile-chapel']];
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => 0])]);
            $events[] = ['type' => 'wanderer_hp_set', 'role_key' => $role, 'payload' => ['hp' => 0]];
        }

        $board = $state->board;
        $board[$chapelKey] = [...$board[$chapelKey], 'location_key' => '', 'relics' => [], 'relics_face_up' => [], 'tucked' => []];
        $state = $state->with(['board' => $board]);
        $events[] = ['type' => 'location_destroyed', 'role_key' => null, 'payload' => ['location_key' => 'fragile-chapel', 'cell' => Board::fromKey($chapelKey)]];

        return [$state, $events];
    }

    /**
     * Public read-only wrapper around relicUnmetConditionReason(), for
     * the controller to compute a "why can't I use this right now"
     * reason per equipped Relic without duplicating any condition
     * logic — same chokepoint useRelic() itself throws through.
     */
    public static function relicUsabilityReason(GameState $state, string $actorRole, string $relicKey): ?string
    {
        // rules.md: "if you are at 0 HP, resting is the only action you
        // can perform" — applies to every Relic use, not just the ones
        // with their own condition, so check it before falling through
        // to relic-specific conditions.
        if ($state->wanderers[$actorRole]['hp'] <= 0) {
            return 'At 0 Health, resting is the only action you can take.';
        }

        // "Inscribe"'s dozen reprints are mechanically distinct despite
        // the shared name (see resolveRelicAction) — dispatch on the
        // untouched $relicKey before the generic baseKey-stripping
        // below would collapse every one of them into the same
        // indistinguishable condition (confirmed in production, game
        // WNNDOZ: inscribe-6 was used while standing nowhere near a
        // Monolith Shard — its specific condition — with no disabled
        // button or warning, spending the action for nothing).
        if (str_starts_with($relicKey, 'inscribe')) {
            return self::inscribeUnmetReason($state, $actorRole, $relicKey);
        }

        // Passive Relics have no "Use" action at all — see useRelic()'s
        // matching check.
        $relic = RelicCatalog::find($relicKey);
        if ($relic !== null && ! $relic->isAction) {
            return "{$relic->name} is a passive Relic — it has no action to use.";
        }

        $baseKey = (string) preg_replace('/-\d+$/', '', $relicKey);

        return self::relicUnmetConditionReason($state, $actorRole, $baseKey);
    }

    private static function inscribeUnmetReason(GameState $state, string $actorRole, string $relicKey): ?string
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $onGolemSpace = self::golemSharesCellWith($state, $actorRole);

        return match ($relicKey) {
            'inscribe', 'inscribe-2', 'inscribe-3', 'inscribe-8', 'inscribe-9', 'inscribe-10' => $onGolemSpace
                ? null
                : 'Inscribe requires sharing the Golem\'s space.',

            'inscribe-4' => self::inscribePrisonJudgmentReady($state, $actorRole)
                ? null
                : 'Inscribe requires you and the Golem in the Prison, no Shame tokens on you, and the Golem at 3+ Shame.',

            'inscribe-5' => $onGolemSpace && $state->wanderers[$actorRole]['shame'] === 0
                ? null
                : 'Inscribe requires sharing the Golem\'s space with no Shame tokens.',

            'inscribe-6' => $onGolemSpace && in_array('monolith_shard', $state->board[Board::key($casterCell)]['infrastructure'], true)
                ? null
                : 'Inscribe requires sharing the Golem\'s space, which must also have a Monolith Shard.',

            'inscribe-7' => 'This triggers automatically when the Golem attacks you.',

            'inscribe-11' => self::allWanderersShareGolemSpace($state)
                ? null
                : 'Inscribe requires every Wanderer sharing the Golem\'s space.',

            'inscribe-12' => $onGolemSpace && self::allWanderersMutuallyAtLeastDistance($state, 2)
                ? null
                : 'Inscribe requires sharing the Golem\'s space while every Wanderer is 2+ spaces from every other.',

            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function chooseStartingCell(GameState $state, string $actorRole, array $move): array
    {
        if ($state->phase !== 'setup') {
            throw new InvalidMoveException('Setup is already finished.');
        }

        if (! in_array($actorRole, $state->pendingSetupWanderers, true)) {
            throw new InvalidMoveException('You have already chosen a starting space.');
        }

        $cell = self::requireCell($move, 'cell');
        $cellKey = Board::key($cell);

        if (! array_key_exists($cellKey, $state->board)) {
            throw new InvalidMoveException('You must start on a Location.');
        }

        $wanderers = $state->wanderersWith($actorRole, ['cell' => $cell]);
        $pending = array_values(array_diff($state->pendingSetupWanderers, [$actorRole]));

        $state = $state->with(['wanderers' => $wanderers, 'pendingSetupWanderers' => $pending]);

        $events = [
            ['type' => 'wanderer_started', 'role_key' => $actorRole, 'payload' => ['cell' => $cell]],
        ];

        [$state, $finishEvents] = self::maybeFinishSetup($state);

        return [$state, [...$events, ...$finishEvents]];
    }

    /**
     * The Emperor also places the Golem anywhere on setup — rules.md:
     * "The Emperor places the Golem on any space."
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function chooseGolemCell(GameState $state, string $actorRole, array $move): array
    {
        if ($state->phase !== 'setup') {
            throw new InvalidMoveException('Setup is already finished.');
        }

        if ($actorRole !== RoleKeys::EMPEROR) {
            throw new InvalidMoveException('Only the Emperor places the Golem.');
        }

        if (! $state->pendingGolemPlacement) {
            throw new InvalidMoveException('The Golem has already been placed.');
        }

        $cell = self::requireCell($move, 'cell');
        $cellKey = Board::key($cell);

        if (! array_key_exists($cellKey, $state->board)) {
            throw new InvalidMoveException('The Golem must start on a Location.');
        }

        $state = $state->with([
            'golem' => $state->golemWith(['cell' => $cell]),
            'pendingGolemPlacement' => false,
        ]);

        $events = [
            ['type' => 'golem_placed', 'role_key' => $actorRole, 'payload' => ['cell' => $cell]],
        ];

        [$state, $finishEvents] = self::maybeFinishSetup($state);

        return [$state, [...$events, ...$finishEvents]];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function maybeFinishSetup(GameState $state): array
    {
        if ($state->pendingSetupWanderers !== [] || $state->pendingGolemPlacement) {
            return [$state, []];
        }

        $state = $state->with(['phase' => 'emperor']);

        return [$state, [['type' => 'round_started', 'role_key' => null, 'payload' => ['round' => $state->round]]]];
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function playLocation(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, RoleKeys::EMPEROR, 'emperor');

        $locationKey = (string) ($move['location_key'] ?? '');
        $cell = self::requireCell($move, 'cell');
        $cellKey = Board::key($cell);

        if (! in_array($locationKey, $state->locationHand, true)) {
            throw new InvalidMoveException('That Location is not in your hand.');
        }

        if (array_key_exists($cellKey, $state->board)) {
            throw new InvalidMoveException('That space is already built.');
        }

        if (! in_array($cellKey, array_map(Board::key(...), Board::placeableCells($state->board)), true)) {
            throw new InvalidMoveException('That space is not adjacent to the city.');
        }

        $location = LocationCatalog::find($locationKey);

        if ($location === null) {
            throw new InvalidMoveException('Unknown Location.');
        }

        foreach ($location->costs as $cost) {
            if (! self::effectiveProduces($state, $cell, $cost)) {
                throw new InvalidMoveException("Must be built adjacent to a space that produces {$cost}.");
            }
        }

        [$relics, $relicDecks] = RelicDealer::dealForLocation($state->relicDecks, $location, $state->board);

        // Fixture outlines: a Location marked has_shard/has_tree on the
        // sheet starts with that fixture already on its space the
        // moment it's built, drawn from the same finite infrastructurePool
        // the Emperor's own per-turn placements come from.
        $infrastructure = [];
        $pool = $state->infrastructurePool;
        if ($location->hasShard && $pool['monolith_shard'] > 0) {
            $infrastructure[] = 'monolith_shard';
            $pool['monolith_shard']--;
        }
        if ($location->hasTree && $pool['fruit_tree'] > 0) {
            $infrastructure[] = 'fruit_tree';
            $pool['fruit_tree']--;
        }

        $hand = array_values(array_diff($state->locationHand, [$locationKey]));
        $board = $state->boardWith($cellKey, [
            'location_key' => $locationKey,
            'placed_round' => $state->round,
            'relics' => $relics,
            'relics_face_up' => [],
            'infrastructure' => $infrastructure,
            'tucked' => [],
        ]);

        // Perplexing Glyph: "When the Emperor plays a Location,
        // Wanderers may choose to immediately move there at no cost."
        // Tracked here regardless of whether Perplexing is actually
        // active — move_to_new_location checks the Glyph itself, same
        // as every other Glyph-gated move.
        $state = $state->with(['board' => $board, 'locationHand' => $hand, 'relicDecks' => $relicDecks, 'infrastructurePool' => $pool, 'locationJustBuiltCell' => $cell]);

        $events = [
            ['type' => 'location_played', 'role_key' => $actorRole, 'payload' => ['location_key' => $locationKey, 'cell' => $cell]],
        ];

        foreach ($infrastructure as $type) {
            $events[] = ['type' => 'infrastructure_placed', 'role_key' => null, 'payload' => ['infrastructure' => $type, 'cell' => $cell]];
        }

        return [$state, $events];
    }

    /**
     * Step 3 of the Emperor phase: up to 2 per turn, from the pool.
     * Monolith Shard allows only 1 per space; Walls cannot go on the
     * Golem's own space. The "cannot trap the Golem" rule isn't enforced
     * — a cooperative Emperor has no reason to actually do that, and
     * checking full board reachability after every Wall placement is more
     * than this is worth yet.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function placeInfrastructure(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, RoleKeys::EMPEROR, 'emperor');

        $type = (string) ($move['infrastructure_type'] ?? '');
        $cell = self::requireCell($move, 'cell');
        $cellKey = Board::key($cell);

        if ($state->infrastructurePlacedThisTurn >= self::infrastructureCapThisTurn($state)) {
            throw new InvalidMoveException('Already placed the maximum Infrastructure this turn.');
        }

        if (! array_key_exists($cellKey, $state->board)) {
            throw new InvalidMoveException('Infrastructure must go on a built Location.');
        }

        if (($state->infrastructurePool[$type] ?? 0) <= 0) {
            throw new InvalidMoveException('None of that Infrastructure left in the pool.');
        }

        if ($type === 'monolith_shard' && in_array('monolith_shard', $state->board[$cellKey]['infrastructure'], true)) {
            throw new InvalidMoveException('Only 1 Monolith Shard per space.');
        }

        if ($type === 'walls' && $state->golem['cell'] !== null && Board::key($state->golem['cell']) === $cellKey) {
            throw new InvalidMoveException('Cannot wall the Golem\'s own space.');
        }

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'infrastructure' => [...$board[$cellKey]['infrastructure'], $type]];

        $pool = $state->infrastructurePool;
        $pool[$type]--;

        $state = $state->with([
            'board' => $board,
            'infrastructurePool' => $pool,
            'infrastructurePlacedThisTurn' => $state->infrastructurePlacedThisTurn + 1,
            'infrastructurePlacedThisTurnLog' => [...$state->infrastructurePlacedThisTurnLog, ['cell' => $cell, 'type' => $type]],
        ]);

        return [$state, [
            ['type' => 'infrastructure_placed', 'role_key' => $actorRole, 'payload' => ['infrastructure' => $type, 'cell' => $cell]],
        ]];
    }

    /**
     * Undoes the Emperor's own most recent Infrastructure placement
     * this turn — "I meant to put that Road somewhere else." Only ever
     * pops from infrastructurePlacedThisTurnLog, which is cleared
     * whenever infrastructurePlacedThisTurn itself resets (a new
     * round's Emperor phase) — so this can never reach back into an
     * earlier turn's placements, only the current one's.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function undoInfrastructurePlacement(GameState $state, string $actorRole): array
    {
        self::requireRole($state, $actorRole, RoleKeys::EMPEROR, 'emperor');

        $log = $state->infrastructurePlacedThisTurnLog;

        if ($log === []) {
            throw new InvalidMoveException('Nothing to undo this turn.');
        }

        $last = array_pop($log);
        $cellKey = Board::key($last['cell']);

        $infrastructure = $state->board[$cellKey]['infrastructure'];
        $index = array_search($last['type'], $infrastructure, true);

        if ($index !== false) {
            unset($infrastructure[$index]);
        }

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'infrastructure' => array_values($infrastructure)];

        $pool = $state->infrastructurePool;
        $pool[$last['type']]++;

        $state = $state->with([
            'board' => $board,
            'infrastructurePool' => $pool,
            'infrastructurePlacedThisTurn' => max(0, $state->infrastructurePlacedThisTurn - 1),
            'infrastructurePlacedThisTurnLog' => $log,
        ]);

        return [$state, [
            ['type' => 'infrastructure_placement_undone', 'role_key' => $actorRole, 'payload' => ['infrastructure' => $last['type'], 'cell' => $last['cell']]],
        ]];
    }

    /**
     * Builder's gloves: "If you are on a space that produces ⚙️ during
     * the Emperor's phase, the Emperor may play +1 infrastructure
     * token." Any Wanderer equipping it on such a space raises the
     * normal 2-per-turn cap to 3.
     */
    public static function infrastructureCapThisTurn(GameState $state): int
    {
        // Productive Glyph: "The Emperor plays 3 Infrastructure during
        // their phase." Same resulting cap as Builder's gloves — not
        // stacked to 4, since neither card's text says "+1."
        if (in_array('productive', $state->golem['glyphs'], true)) {
            return 3;
        }

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array('builders-gloves', $state->wanderers[$role]['equipped'], true)) {
                continue;
            }

            $location = LocationCatalog::find($state->board[Board::key($cell)]['location_key']);

            if ($location !== null && $location->producesEmoji === '⚙️') {
                return 3;
            }
        }

        return 2;
    }

    /**
     * 🔎 — draws a random Relic from the current space's face-down pile
     * into the Wanderer's hand ("holding"). Costs 1 action; an empty pile
     * still costs the action, same as a physical search coming up short.
     *
     * Event-type Relics have no equip slot — rules.md: "If you discover
     * an Event, resolve immediately and trash." It's never added to
     * `holding`, since there'd be no way to ever do anything with it
     * there — see resolveEventRelic for which ones have real,
     * executed effects.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function searchLocation(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];

        if ($wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        self::requireNotExhausted($wanderer);

        $cellKey = Board::key($wanderer['cell']);
        $pile = $state->board[$cellKey]['relics'];

        if ($pile === []) {
            throw new InvalidMoveException('There are no face-down Relics left here to search for.');
        }

        $index = array_rand($pile);
        $found = $pile[$index];
        unset($pile[$index]);
        $pile = array_values($pile);

        $foundRelic = RelicCatalog::find($found);
        $isEvent = $foundRelic !== null && $foundRelic->isEvent;

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'relics' => $pile];

        // Discovering an Event refunds the action that paid for the
        // search — confirmed with the designer 2026-11-14: the search
        // itself still happens (the face-down pile shrinks either way),
        // but finding an Event doesn't cost the Wanderer their turn.
        $wanderers = $state->wanderersWith($actorRole, [
            'actions_remaining' => $isEvent ? $wanderer['actions_remaining'] : $wanderer['actions_remaining'] - 1,
            'holding' => $isEvent ? $wanderer['holding'] : [...$wanderer['holding'], $found],
        ]);

        $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);

        $events = [
            ['type' => 'location_searched', 'role_key' => $actorRole, 'payload' => ['found' => $found, 'trashed_as_event' => $isEvent]],
        ];

        if ($isEvent) {
            [$state, $eventRelicEvents] = self::resolveEventRelic($state, $actorRole, $found, $move);
            $events = [...$events, ...$eventRelicEvents];
        }

        return [$state, $events];
    }

    /**
     * Free at any time — confirmed via chat 2026-11-13. Bumps whatever
     * Relic was already in that slot onto the ground; a slot held by a
     * Burn token can't be equipped into at all, rules.md: "They cannot
     * equip a Relic there until the Burn is healed."
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function equipRelic(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        $wanderer = $state->wanderers[$actorRole];
        $relicKey = (string) ($move['relic_key'] ?? '');
        $slot = (string) ($move['slot'] ?? '');

        if (! in_array($relicKey, $wanderer['holding'], true)) {
            throw new InvalidMoveException('You are not holding that Relic.');
        }

        $relic = RelicCatalog::find($relicKey);
        // Creative alterations: "Your Relics can go into any slot, and
        // you can ignore Types" — bypasses the type match, but the
        // slot itself still has to be one of the 5 real equip slots.
        $ignoresTypes = in_array('creative-alterations', $wanderer['equipped'], true);

        if ($relic === null || ! array_key_exists($slot, $wanderer['equipped']) || (! $ignoresTypes && ! self::relicFitsSlot($relic->slot, $slot))) {
            throw new InvalidMoveException('That Relic does not fit that slot.');
        }

        // Tyrannical Glyph: "Wanderers cannot equip Relics with 🌿."
        if (in_array('tyrannical', $state->golem['glyphs'], true) && str_contains($relic->effect, '🌿')) {
            throw new InvalidMoveException('Tyrannical: Relics with 🌿 cannot be equipped right now.');
        }

        // An expansive mind: "You can add two more Mind cards to this
        // slot." mind_2/mind_3 only exist while it occupies 'mind' itself.
        if (($slot === 'mind_2' || $slot === 'mind_3') && $wanderer['equipped']['mind'] !== 'an-expansive-mind') {
            throw new InvalidMoveException('That slot requires An expansive mind to be equipped first.');
        }

        $displaced = $wanderer['equipped'][$slot];

        if (self::isStatusToken($displaced)) {
            throw new InvalidMoveException('That slot is blocked by a status token.');
        }

        // rules.md: Bound Relics "cannot drop" — equipping over one
        // would displace it face-up exactly like a drop, so it's
        // blocked the same way a status token blocks the slot.
        if (is_string($displaced) && RelicCatalog::find($displaced)?->isBound === true) {
            throw new InvalidMoveException('That slot holds a Bound Relic, which cannot be displaced.');
        }

        $cellKey = Board::key($wanderer['cell']);
        $board = $state->board;

        if ($displaced !== null) {
            // A displaced Relic was equipped (known) a moment ago — it
            // lands face-up, same as a deliberate drop, not back into the
            // anonymous face-down pile.
            $board[$cellKey] = [...$board[$cellKey], 'relics_face_up' => [...$board[$cellKey]['relics_face_up'], $displaced]];
        }

        $equipped = $wanderer['equipped'];
        $equipped[$slot] = $relicKey;

        $wanderers = $state->wanderersWith($actorRole, [
            'equipped' => $equipped,
            'holding' => array_values(array_diff($wanderer['holding'], [$relicKey])),
        ]);

        $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);
        $events = [
            ['type' => 'relic_equipped', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'slot' => $slot]],
        ];

        // Cabalist's Gown: "When you equip this, your Health goes to
        // 5, and cannot rise above 5."
        if ($relicKey === 'cabalists-gown') {
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => 5])]);
            $events[] = ['type' => 'wanderer_hp_set', 'role_key' => $actorRole, 'payload' => ['hp' => 5]];
        }

        return [$state, $events];
    }

    /**
     * Free at any time, per rules.md: "You may pick up and drop Relics in
     * your space at any time. This does not cost an action."
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function dropRelic(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        $wanderer = $state->wanderers[$actorRole];
        $relicKey = (string) ($move['relic_key'] ?? '');

        if (self::isStatusToken($relicKey)) {
            throw new InvalidMoveException('Status tokens are not dropped this way.');
        }

        // rules.md: "You cannot drop, tuck, trash, or trade this" — a
        // blanket rule on Bound Relics themselves, not just once
        // equipped. A newly-found one that hasn't been equipped yet
        // can't be dropped either; it either gets equipped or sits in
        // holding (the vanishingly rare case where every eligible slot
        // is already blocked by a Burn token or another Bound
        // Relic isn't handled — rules.md doesn't say what happens then
        // either).
        if (RelicCatalog::find($relicKey)?->isBound === true) {
            throw new InvalidMoveException('Bound Relics cannot be dropped — they must be equipped.');
        }

        $equipped = $wanderer['equipped'];
        $fromSlot = array_search($relicKey, $equipped, true);

        if ($fromSlot !== false) {
            $equipped[$fromSlot] = null;
        } elseif (in_array($relicKey, $wanderer['holding'], true)) {
            // handled below via holding diff
        } else {
            throw new InvalidMoveException('You do not have that Relic.');
        }

        $cellKey = Board::key($wanderer['cell']);
        $board = $state->board;
        // rules.md: "Dropping a relic leaves it face-up on your current
        // space."
        $board[$cellKey] = [...$board[$cellKey], 'relics_face_up' => [...$board[$cellKey]['relics_face_up'], $relicKey]];

        $tuckedCards = $wanderer['tucked_cards'];
        $lostCards = $tuckedCards[$relicKey] ?? [];
        unset($tuckedCards[$relicKey]);

        $wanderers = $state->wanderersWith($actorRole, [
            'equipped' => $equipped,
            'holding' => array_values(array_diff($wanderer['holding'], [$relicKey])),
            'tucked_cards' => $tuckedCards,
        ]);

        $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);

        $events = [
            ['type' => 'relic_dropped', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]],
        ];

        if ($lostCards !== [] && $relicKey === 'golem-log') {
            // "If you drop this Relic, return the cards to its discard
            // pile and lose your progress." (Defanging tool's own text
            // doesn't return its tucked card anywhere — it's just lost.)
            $golem = $state->golem;
            $golem['discard_pile'] = [...$golem['discard_pile'], ...$lostCards];
            $state = $state->with(['golem' => $golem]);
            $events[] = ['type' => 'golem_log_progress_lost', 'role_key' => $actorRole, 'payload' => ['cards' => $lostCards]];
        } elseif ($lostCards !== []) {
            $events[] = ['type' => 'tucked_progress_lost', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'cards' => $lostCards]];
        }

        return [$state, $events];
    }

    /**
     * Free at any time — the other half of the drop rule above. Unlike
     * search, this takes a specific, known (already face-up) Relic.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function pickupRelic(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        $wanderer = $state->wanderers[$actorRole];
        $relicKey = (string) ($move['relic_key'] ?? '');
        $cellKey = Board::key($wanderer['cell']);
        $pile = $state->board[$cellKey]['relics_face_up'];

        if (! in_array($relicKey, $pile, true)) {
            throw new InvalidMoveException('That Relic is not here.');
        }

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'relics_face_up' => array_values(array_diff($pile, [$relicKey]))];

        $wanderers = $state->wanderersWith($actorRole, ['holding' => [...$wanderer['holding'], $relicKey]]);

        $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);

        return [$state, [
            ['type' => 'relic_picked_up', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]],
        ]];
    }

    /**
     * Wanderer action 4: "Perform the Action from one of your Relics
     * equipped." Costs 1 action, same as Move/Search. Only a hand-picked
     * batch of the 63 Action-type Relics have real, executed behavior —
     * see the switch below; everything else is logged but not applied,
     * same honesty pattern as Golem cards. "Gain 📜" clauses attached to
     * the implemented ones are deliberately skipped too (not just the
     * unimplemented Relics) — scoring sources are a separate pass.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function useRelic(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];

        if ($wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        self::requireNotExhausted($wanderer);

        $relicKey = (string) ($move['relic_key'] ?? '');

        if (self::isStatusToken($relicKey) || ! in_array($relicKey, $wanderer['equipped'], true)) {
            throw new InvalidMoveException('That Relic is not equipped.');
        }

        $relic = RelicCatalog::find($relicKey);

        // Passive Relics (e.g. Prohibition of inscribing) have no "Use"
        // concept at all — their effect is just being equipped. Without
        // this, a Passive Relic with no case in resolveRelicAction's
        // match below silently falls through to its no-op default arm,
        // spending the action for nothing (confirmed in production: the
        // UI showed a "Use" button for one and let it burn through
        // actions). inscribe-7 is Passive too, but excluded here since
        // it's routed through resolveInscribe/inscribeUnmetReason
        // instead, which already rejects it with a more specific reason.
        if ($relic !== null && ! $relic->isAction && ! str_starts_with($relicKey, 'inscribe')) {
            throw new InvalidMoveException("{$relic->name} is a passive Relic — it has no action to use.");
        }

        $baseKey = (string) preg_replace('/-\d+$/', '', $relicKey);

        // For Relics whose printed condition is knowable from state alone
        // (no target/cell choice needed), reject the attempt outright
        // rather than spending the action on a guaranteed no-op — see
        // relicUsabilityReason() (handles "Inscribe"'s dozen mechanically
        // distinct reprints specially, same as here). Relics needing a
        // $move-supplied target (an ally, a cell, etc.) aren't covered
        // here; their conditions depend on a choice the player hasn't
        // made yet.
        $unmetReason = self::relicUsabilityReason($state, $actorRole, $relicKey);

        if ($unmetReason !== null) {
            throw new InvalidMoveException($unmetReason);
        }

        $wanderers = $state->wanderersWith($actorRole, ['actions_remaining' => $wanderer['actions_remaining'] - 1]);
        $state = $state->with(['wanderers' => $wanderers]);

        $events = [
            ['type' => 'relic_used', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'name' => $relic?->name]],
        ];

        $hpBeforeDivineIcon = array_map(fn (array $w) => $w['hp'], $state->wanderers);

        [$state, $relicEvents, $trash] = self::resolveRelicAction($state, $actorRole, $baseKey, $relicKey, $move);
        $events = [...$events, ...$relicEvents];

        [$state, $divineIconEvents] = self::applyDivineIconBoost($state, $actorRole, $hpBeforeDivineIcon);
        $events = [...$events, ...$divineIconEvents];

        [$state, $glyphHealEvents] = self::applyGlyphHealBoost($state, $actorRole, $hpBeforeDivineIcon);
        $events = [...$events, ...$glyphHealEvents];

        // Golem sanctuary: "When playing a 🌿 Relic in the Golem's
        // space, tuck the Relic here and you may trash one of its
        // Glyphs." Reuses the same HP-diff used for Divine Icon to
        // detect a 🌿 effect without special-casing every heal Relic.
        // Overrides the Relic's own trash clause — it gets tucked
        // here either way.
        $healedAnyone = (bool) array_filter(
            $state->wanderers,
            fn (array $w, string $r) => $w['hp'] > ($hpBeforeDivineIcon[$r] ?? $w['hp']),
            ARRAY_FILTER_USE_BOTH
        );
        $casterCellKey = Board::key($state->wanderers[$actorRole]['cell']);
        $onGolemSanctuary = $healedAnyone
            && $state->board[$casterCellKey]['location_key'] === 'golem-sanctuary'
            && self::golemSharesCellWith($state, $actorRole);

        // Vindictive Glyph: "When Wanderers play Relics with 🌿, the
        // Golem immediately 👊5 all Wanderers in its space." Same
        // healedAnyone HP-diff detection as everything else here.
        if ($healedAnyone && in_array('vindictive', $state->golem['glyphs'], true)) {
            [$state, $vindictiveEvents] = self::golemAttackHere($state, 5);
            $events = [...$events, ...$vindictiveEvents];
        }

        if ($onGolemSanctuary && in_array($relicKey, $state->wanderers[$actorRole]['equipped'], true)) {
            $equipped = $state->wanderers[$actorRole]['equipped'];
            $equipped[array_search($relicKey, $equipped, true)] = null;
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['equipped' => $equipped])]);

            $board = $state->board;
            $board[$casterCellKey] = [...$board[$casterCellKey], 'tucked' => [...$board[$casterCellKey]['tucked'], $relicKey]];
            $state = $state->with(['board' => $board]);

            $events[] = ['type' => 'relic_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'cell' => $state->wanderers[$actorRole]['cell']]];

            // "...and you may trash one of its Glyphs." Optional — only
            // acted on if the caller actually names one of the Golem's
            // current active Glyphs via $move['trash_golem_glyph_key'].
            $requestedGlyphKey = $move['trash_golem_glyph_key'] ?? null;

            if (is_string($requestedGlyphKey)) {
                $glyphs = $state->golem['glyphs'];
                $glyphIndex = array_search($requestedGlyphKey, $glyphs, true);

                if ($glyphIndex !== false) {
                    unset($glyphs[$glyphIndex]);
                    $state = $state->with(['golem' => $state->golemWith(['glyphs' => array_values($glyphs)])]);
                    $events[] = ['type' => 'glyph_discarded', 'role_key' => $actorRole, 'payload' => ['glyph_key' => $requestedGlyphKey]];
                }
            }
        } elseif ($trash) {
            $state = self::trashEquippedRelic($state, $actorRole, $relicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]];
        }

        return [$state, $events];
    }

    /**
     * An insight from the future's own use_relic call needs a
     * location_key the player can't possibly supply without first
     * seeing what's actually in the Emperor's (hidden) Location deck —
     * there was no way to satisfy that before this move existed, so
     * the Relic was a guaranteed silent no-op every single time it was
     * used (confirmed in production, game OT0TCT: action spent, no
     * trash, no Prophecy). Free, no action cost, no state change at
     * all — purely a permission check (the caller must actually have
     * this Relic equipped) that lets the controller attach the real
     * deck contents to its own response, visible only to the Wanderer
     * who asked. The follow-up use_relic call (with location_key now
     * filled in) is what actually resolves the effect.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function peekLocationDeck(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        $relicKey = (string) ($move['relic_key'] ?? '');

        if (self::isStatusToken($relicKey) || ! in_array($relicKey, $state->wanderers[$actorRole]['equipped'], true)) {
            throw new InvalidMoveException('That Relic is not equipped.');
        }

        return [$state, []];
    }

    /**
     * Divine Icon: "All 🌿 Relics used in your space by you or your
     * Allies get +3. If your space produces 📖, +6 instead." Rather
     * than special-casing every heal Relic individually, this diffs
     * every Wanderer's HP across the whole use_relic call and adds the
     * boost to any positive gain — which only ever happens for a 🌿
     * effect in the first place, so the diff alone is enough to find
     * them. Only triggers if the caster (not the target) shares a space
     * with a Divine Icon holder, matching "used in your space."
     *
     * @param  array<string, int>  $hpBefore
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyDivineIconBoost(GameState $state, string $actorRole, array $hpBefore): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $casterCellKey = $casterCell === null ? null : Board::key($casterCell);
        $hasDivineIcon = false;

        foreach (self::activeWanderers($state) as $role => $cell) {
            if ($casterCellKey !== null && Board::key($cell) === $casterCellKey && in_array('divine-icon', $state->wanderers[$role]['equipped'], true)) {
                $hasDivineIcon = true;
                break;
            }
        }

        if (! $hasDivineIcon) {
            return [$state, []];
        }

        $casterLocation = $casterCell === null ? null : LocationCatalog::find($state->board[$casterCellKey]['location_key']);
        $boost = $casterLocation?->producesEmoji === '📖' ? 6 : 3;
        $events = [];

        foreach ($state->wanderers as $role => $wanderer) {
            $gained = $wanderer['hp'] - ($hpBefore[$role] ?? $wanderer['hp']);

            if ($gained <= 0) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $wanderer['hp'] + $boost);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Blessed: "When Wanderers play Relics with 🌿, they heal +2."
     * Congenial: "Relics with 🌿 get +3 if played while on a space
     * with another Wanderer." Both detected the exact same way Divine
     * Icon is (an HP-diff snapshot across every Wanderer around this
     * use_relic call) rather than special-casing every heal Relic —
     * same reused pattern as Golem sanctuary's tuck-on-heal check.
     *
     * @param  array<string, int>  $hpBefore
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyGlyphHealBoost(GameState $state, string $actorRole, array $hpBefore): array
    {
        $glyphs = $state->golem['glyphs'];
        $blessed = in_array('blessed', $glyphs, true);

        $congenial = false;
        if (in_array('congenial', $glyphs, true)) {
            $casterCell = $state->wanderers[$actorRole]['cell'];
            if ($casterCell !== null) {
                foreach (self::activeWanderers($state) as $role => $cell) {
                    if ($role !== $actorRole && Board::key($cell) === Board::key($casterCell)) {
                        $congenial = true;
                        break;
                    }
                }
            }
        }

        if (! $blessed && ! $congenial) {
            return [$state, []];
        }

        $bonus = ($blessed ? 2 : 0) + ($congenial ? 3 : 0);
        $events = [];

        foreach ($state->wanderers as $role => $wanderer) {
            $gained = $wanderer['hp'] - ($hpBefore[$role] ?? $wanderer['hp']);

            if ($gained <= 0) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $wanderer['hp'] + $bonus);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * For Action Relics whose printed condition is entirely determined
     * by current state (no target cell/Ally the player still has to
     * pick), returns a human-readable rejection reason if that condition
     * isn't met right now, or null if it is (or if this Relic isn't one
     * of the ones covered here, in which case it's always allowed to
     * attempt — same as before this method existed). Each case here
     * mirrors a guard clause already inside that Relic's own
     * resolveXxx() method; this is deliberately the single place both
     * useRelic() (to reject the attempt outright) and the controller's
     * per-Relic "usable" flag for the frontend (to disable its Use
     * button) read from, so the two can't drift apart.
     *
     * Relics needing a $move-supplied target (an Ally, a cell, a choice
     * of Glyphs/cards, etc.) aren't covered — their condition depends on
     * a choice the player hasn't made yet, so there's nothing to
     * pre-check before they've made it.
     */
    private static function relicUnmetConditionReason(GameState $state, string $actorRole, string $baseKey): ?string
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $casterCellKey = Board::key($casterCell);
        $golemCell = $state->golem['cell'];
        $location = LocationCatalog::find($state->board[$casterCellKey]['location_key']);
        $instruments = ['lute', 'pipe', 'tabor-drum'];

        return match ($baseKey) {
            'pray' => $golemCell !== null && Board::distance($casterCell, $golemCell, $state->board) === 3
                ? null
                : 'Pray requires being exactly 3 spaces from the Golem.',

            'peacemakers-spirit', 'whip-of-justice', 'armistice', 'cabalist-brand', 'golem-intuition', 'golem-prod', 'defanging-tool', 'shear', 'golem-counter' => self::golemSharesCellWith($state, $actorRole)
                ? null
                : 'This requires sharing the Golem\'s space.',

            'pointed-finger' => self::golemSharesCellWith($state, $actorRole)
                ? null
                : 'Accusing mind requires sharing the Golem\'s space.',

            'divine-aura' => self::hasMonolithShardFor($state, $actorRole, $casterCell)
                ? null
                : 'Divine aura requires standing on a Monolith Shard.',

            'golem-whistle' => ! in_array($casterCellKey, $state->wallKeys(), true)
                ? null
                : 'Golem whistle cannot summon the Golem onto a Walls space.',

            'divine-robes' => $location?->producesEmoji === '📖'
                ? null
                : 'Divine robes requires standing on a space that produces 📖.',

            'divine-solidarity' => self::anyAllyAt($state, $actorRole, $casterCellKey)
                ? null
                : 'Divine solidarity requires an Ally on your space.',

            'magistrates-robes' => $location?->producesEmoji === '⚖️'
                ? null
                : 'Magistrate\'s robes requires standing on a space that produces ⚖️.',

            'silent-hunting-cloak' => $location?->producesEmoji === '🐾' && self::golemSharesCellWith($state, $actorRole)
                ? null
                : 'Silent hunting cloak requires sharing the Golem\'s space on a 🐾 space.',

            'soothing-lullaby' => self::golemSharesCellWith($state, $actorRole)
                && count(array_intersect($instruments, self::equippedAt($state, $casterCellKey))) === count($instruments)
                ? null
                : 'Soothing Lullaby requires sharing the Golem\'s space with all 3 🎶 Relics equipped there.',

            'divine-chant' => $state->board[$casterCellKey]['location_key'] === 'cathedral'
                && $golemCell !== null && Board::distance($casterCell, $golemCell, $state->board) >= 2
                && count(array_intersect($instruments, self::equippedAt($state, $casterCellKey))) === count($instruments)
                ? null
                : 'Divine Chant requires the Cathedral, the Golem 2+ spaces away, and all 3 🎶 Relics equipped on your space.',

            'poof' => self::monolithShardsWithin($state, $casterCell, 2) >= 12
                ? null
                : 'Poof requires all 12 Monolith Shards within 2 spaces of you.',

            'scapegoats-catharsis' => count(array_intersect(
                ['dark-shackles', 'dark-limitations', 'dark-sacrifice', 'dark-sigil'],
                array_values($state->wanderers[$actorRole]['equipped']),
            )) === 4
                ? null
                : 'Scapegoat\'s catharsis requires all 4 Dark Relics equipped.',

            're-creation' => $state->trashedRelics !== []
                ? null
                : 'Re-creation requires at least 1 Relic to have been trashed so far.',

            'jericho-inscription' => self::jerichoInscriptionReady($state, $actorRole)
                ? null
                : 'Jericho inscription requires a Wall adjacent to you with the Golem on the space beyond it, and all 3 🎶 Relics equipped adjacent to that Wall.',

            'aural-inscription' => self::auralInscriptionReady($state)
                ? null
                : 'Aural inscription requires every Wanderer exactly 1 space from the Golem, each on their own space, with all 3 🎶 Relics equipped somewhere among them.',

            default => null,
        };
    }

    private static function anyAllyAt(GameState $state, string $actorRole, string $cellKey): bool
    {
        foreach (self::activeWanderers($state) as $role => $cell) {
            if ($role !== $actorRole && Board::key($cell) === $cellKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<mixed>
     */
    private static function equippedAt(GameState $state, string $cellKey): array
    {
        $equipped = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) === $cellKey) {
                $equipped = [...$equipped, ...array_values($state->wanderers[$role]['equipped'])];
            }
        }

        return $equipped;
    }

    /**
     * @param  array{0: int, 1: int}  $cell
     */
    private static function monolithShardsWithin(GameState $state, array $cell, int $distance): int
    {
        $count = 0;

        foreach (array_map(Board::key(...), Board::cellsWithinDistance($cell, $distance, $state->board)) as $key) {
            $count += count(array_filter($state->board[$key]['infrastructure'], fn (string $i) => $i === 'monolith_shard'));
        }

        return $count;
    }

    private static function jerichoInscriptionReady(GameState $state, string $actorRole): bool
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $instruments = ['lute', 'pipe', 'tabor-drum'];
        $golemCell = $state->golem['cell'];

        foreach (Board::neighbors($casterCell) as $direction => $wallCell) {
            $wallKey = Board::key($wallCell);

            if (! array_key_exists($wallKey, $state->board) || ! in_array('walls', $state->board[$wallKey]['infrastructure'], true)) {
                continue;
            }

            $beyondKey = Board::key(Board::neighbor($wallCell, $direction));

            if ($golemCell === null || Board::key($golemCell) !== $beyondKey) {
                continue;
            }

            $wallNeighborKeys = array_map(Board::key(...), Board::occupiedNeighbors($wallCell, $state->board));
            $equippedNearWall = [];

            foreach (self::activeWanderers($state) as $role => $cell) {
                if (in_array(Board::key($cell), $wallNeighborKeys, true)) {
                    $equippedNearWall = [...$equippedNearWall, ...array_values($state->wanderers[$role]['equipped'])];
                }
            }

            if (count(array_intersect($instruments, $equippedNearWall)) === count($instruments)) {
                return true;
            }
        }

        return false;
    }

    private static function auralInscriptionReady(GameState $state): bool
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return false;
        }

        $cells = self::activeWanderers($state);
        $seenCellKeys = [];
        $equippedAnywhere = [];

        foreach ($cells as $role => $cell) {
            if (Board::distance($golemCell, $cell, $state->board) !== 1) {
                return false;
            }

            $cellKey = Board::key($cell);

            if (isset($seenCellKeys[$cellKey])) {
                return false;
            }

            $seenCellKeys[$cellKey] = true;
            $equippedAnywhere = [...$equippedAnywhere, ...array_values($state->wanderers[$role]['equipped'])];
        }

        $instruments = ['lute', 'pipe', 'tabor-drum'];

        return count(array_intersect($instruments, $equippedAnywhere)) === count($instruments);
    }

    /**
     * $baseKey has any "-N" disambiguating suffix stripped — safe for
     * every Relic name except "Inscribe," whose dozen reprints are
     * mechanically distinct cards despite the shared title (different
     * slot, condition, and Glyph color each), so those are dispatched
     * on the untouched $relicKey instead, before baseKey collapses them
     * all together.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool} 2 = whether the Relic trashes itself after use
     */
    private static function resolveRelicAction(GameState $state, string $actorRole, string $baseKey, string $relicKey, array $move): array
    {
        if (str_starts_with($relicKey, 'inscribe')) {
            return self::resolveInscribe($state, $actorRole, $relicKey);
        }

        return match ($baseKey) {
            'artists-insight' => [...self::relicMove($state, $actorRole, $move, 2), false],
            'a-keen-eye' => [...self::relicSearchN($state, $actorRole, 2), false],
            'alms-for-the-poor' => [...self::relicHealBelowThreshold($state, 6), false],
            'burial-robes' => [...self::relicHealSharedCell($state, $actorRole, 15), false],
            'divine-healing-oil' => [...self::relicDivineHealingOil($state, $actorRole, $move), false],
            'golem-disguise' => [...self::relicDiscardGolemTopCard($state), false],
            'golem-whistle' => [...self::relicSummonGolem($state, $actorRole), false],
            'quantum-robes' => [...self::relicSwapWithAlly($state, $actorRole, $move), false],
            'last-rites' => [...self::relicHealAllyToMax($state, $actorRole, $move), true],
            'holy-water' => [...self::relicRemoveStatus($state, $actorRole, $move), false],
            'balm' => [...self::relicBalm($state, $actorRole, $move), false],
            'hands-of-forgiveness' => [...self::relicRemoveAllStatus($state, $actorRole, $move), false],
            'whip-of-justice' => self::relicTapForGolemShame($state, $actorRole),
            'peacemakers-spirit' => [...self::relicTapGolemIfSharingCell($state, $actorRole, 2), false],
            'blinding-explosion' => self::relicBlindingExplosion($state, $actorRole),
            'soothing-lullaby' => self::relicSoothingLullaby($state, $actorRole),
            'golem-prod' => [...self::relicGolemProd($state, $actorRole, $move), false],
            'golem-intuition' => [...self::relicGolemIntuition($state, $actorRole), false],
            'golem-anthropology' => [...self::relicSearchN($state, $actorRole, self::golemSharesCellWith($state, $actorRole) ? 3 : 1), false],
            'pointed-finger' => self::relicPointedFinger($state, $actorRole),
            'divine-aura' => [...self::relicDivineAura($state, $actorRole), false],
            'emperors-staff' => [...self::relicEmperorsStaff($state, $actorRole), false],
            'ban-of-dangerous-ideas' => [...self::relicBanOfDangerousIdeas($state), true],
            'writ-of-solitude' => [...self::relicWritOfSolitude($state), true],
            'teleportation-stone' => [...self::relicTeleportationStone($state, $actorRole, $move), false],
            'hands-of-deliverance' => [...self::relicHandsOfDeliverance($state, $move), false],
            'divine-robes' => [...self::relicDivineRobes($state, $actorRole), false],
            'divine-solidarity' => [...self::relicDivineSolidarity($state, $actorRole), false],
            'warlocks-robes' => [...self::relicWarlocksRobes($state, $actorRole, $move), false],
            'emperors-diary' => [...self::relicEmperorsDiary($state, $actorRole), false],
            'emperors-robes' => [...self::relicEmperorsRobes($state, $actorRole, $move), false],
            'an-insight-from-the-future' => self::relicInsightFromTheFuture($state, $move),
            'aural-inscription' => self::relicAuralInscription($state, $actorRole),
            'divine-chant' => self::relicDivineChant($state, $actorRole),
            'magistrates-robes' => [...self::relicMagistratesRobes($state, $actorRole, $move), false],
            'immolation-rags' => self::relicImmolationRags($state, $actorRole),
            'divine-cabalist-pyre' => self::relicDivineCabalistPyre($state, $actorRole, $move),
            'armistice' => self::relicArmistice($state, $actorRole, $move),
            'cabalist-brand' => self::relicCabalistBrand($state, $actorRole, $move),
            'poof' => self::relicPoof($state, $actorRole),
            'scapegoats-catharsis' => self::relicScapegoatsCatharsis($state, $actorRole),
            'silent-hunting-cloak' => [...self::relicSilentHuntingCloak($state, $actorRole), false],
            'jericho-inscription' => self::relicJerichoInscription($state, $actorRole),
            'past-troubles' => self::relicPastTroubles($state),
            'pray' => self::relicPray($state, $actorRole),
            're-creation' => self::relicReCreation($state, $actorRole),
            'cabalist-brew' => self::relicCabalistBrew($state, $actorRole, $move),
            'defanging-tool' => self::relicDefangingTool($state, $actorRole),
            'golem-log' => self::offerGolemLog($state, $actorRole),
            'merchants-gloves' => [...self::relicMerchantsGloves($state, $actorRole, $move), false],
            'emperors-timepiece' => self::relicEmperorsTimepiece($state, $actorRole),
            'rod' => [...self::relicRod($state, $move), true],
            'shear' => [...self::relicShear($state, $move), true],
            'solidarity-badge' => [...self::relicSolidarityBadge($state, $actorRole, $move), true],
            'golem-influence' => [...self::relicGolemInfluence($state, $move), true],
            'darkness-into-light' => [...self::relicDarknessIntoLight($state), true],
            'harbinger-of-death' => [...self::relicHarbingerOfDeath($state, $actorRole), true],
            'golem-counter' => [...self::relicGolemCounter($state, $move), true],
            'shroud-of-time' => [...self::relicShroudOfTime($state, $actorRole), false],
            'hands-of-transport' => [...self::relicHandsOfTransport($state, $move), false],
            'aura-eruption' => self::relicAuraEruption($state, $actorRole),
            default => [$state, [], false],
        };
    }

    /**
     * Rod: "Discard one of the active Glyphs from the Golem's mat.
     * Trash this." $move['glyph_key'] picks which currently-active
     * Glyph; a missing key or one not actually active is a no-op on the
     * discard, but the Relic still trashes (same "Then, trash this" is
     * unconditional" reading used throughout).
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicRod(GameState $state, array $move): array
    {
        $glyphKey = (string) ($move['glyph_key'] ?? '');
        $glyphs = $state->golem['glyphs'];
        $index = array_search($glyphKey, $glyphs, true);

        if ($index === false) {
            return [$state, []];
        }

        unset($glyphs[$index]);
        $glyphs = array_values($glyphs);
        $glyphDiscardPile = [...$state->glyphDiscardPile, $glyphKey];
        $state = $state->with(['golem' => $state->golemWith(['glyphs' => $glyphs]), 'glyphDiscardPile' => $glyphDiscardPile]);

        return [$state, [['type' => 'glyph_discarded', 'role_key' => null, 'payload' => ['glyph_key' => $glyphKey]]]];
    }

    /**
     * Shear: "If you are in the Golem's space: Trash any number of the
     * face-up prepared Golem Cards from its mat as you choose. Trash
     * this." "Prepared" cards are the Golem's revealed row
     * ($golem['revealed'], size GOLEM_REVEALED_SIZE). Unlike discarding
     * a Golem card (which recycles it into the discard pile to
     * eventually reshuffle back in), trashing here removes it from
     * circulation permanently — there's no recovery mechanic for Golem
     * cards the way Re-creation recovers trashed Relics, so it simply
     * never returns. $move['card_keys'] is a list of 0+ keys among the
     * currently-revealed cards; unrecognized keys are ignored.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicShear(GameState $state, array $move): array
    {
        $requested = array_values(array_filter((array) ($move['card_keys'] ?? []), 'is_string'));

        if ($requested === []) {
            return [$state, []];
        }

        $golem = $state->golem;
        $events = [];

        foreach ($golem['revealed'] as $i => $entry) {
            if (in_array($entry['key'], $requested, true)) {
                unset($golem['revealed'][$i]);
                $events[] = ['type' => 'golem_card_trashed', 'role_key' => null, 'payload' => ['card_key' => $entry['key']]];
            }
        }

        $golem['revealed'] = array_values($golem['revealed']);
        $state = $state->with(['golem' => $golem]);
        $state = self::refillGolemRevealed($state);

        return [$state, $events];
    }

    /**
     * Solidarity badge: "Choose an ally. They may perform the action
     * from one of their Relics, even though it is not their turn."
     * $move['ally_role'] picks the ally, $move['ally_relic_key'] picks
     * which of THEIR equipped Relics to fire — resolved exactly as if
     * the ally had played it on their own turn (same resolveRelicAction
     * chokepoint, same self-trash semantics for that Relic), just with
     * the active player choosing the moment. Deliberately a distinct
     * key from $move['relic_key'] — that one is already consumed by
     * useRelic() to mean "Solidarity badge itself." A missing/invalid
     * ally or an un-equipped Relic key is a no-op.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicSolidarityBadge(GameState $state, string $actorRole, array $move): array
    {
        $allyRole = (string) ($move['ally_role'] ?? '');
        $relicKey = (string) ($move['ally_relic_key'] ?? '');

        if ($allyRole === $actorRole || ! isset($state->wanderers[$allyRole])) {
            return [$state, []];
        }

        if (! in_array($relicKey, $state->wanderers[$allyRole]['equipped'], true)) {
            return [$state, []];
        }

        $baseKey = (string) preg_replace('/-\d+$/', '', $relicKey);
        [$state, $events, $trashUsed] = self::resolveRelicAction($state, $allyRole, $baseKey, $relicKey, $move);

        $events[] = ['type' => 'solidarity_badge_used', 'role_key' => $actorRole, 'payload' => ['ally_role' => $allyRole, 'relic_key' => $relicKey]];

        if ($trashUsed) {
            $state = self::trashEquippedRelic($state, $allyRole, $relicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $allyRole, 'payload' => ['relic_key' => $relicKey]];
        }

        return [$state, $events];
    }

    /**
     * Golem influence: "Choose an active Golem Glyph, and discard it.
     * Replace it with a random Glyph of the same type." Same discard +
     * addGlyphOfType composition as Rift in time (resolveRiftInTime),
     * just available as a direct Action rather than an Event that has
     * to park itself on a pending choice.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicGolemInfluence(GameState $state, array $move): array
    {
        $glyphKey = (string) ($move['glyph_key'] ?? '');
        $glyphs = $state->golem['glyphs'];
        $index = array_search($glyphKey, $glyphs, true);

        if ($index === false) {
            return [$state, []];
        }

        $type = GlyphCatalog::find($glyphKey)?->type;

        if ($type === null) {
            return [$state, []];
        }

        unset($glyphs[$index]);
        $glyphs = array_values($glyphs);
        $glyphDiscardPile = [...$state->glyphDiscardPile, $glyphKey];
        $state = $state->with(['golem' => $state->golemWith(['glyphs' => $glyphs]), 'glyphDiscardPile' => $glyphDiscardPile]);

        $events = [['type' => 'glyph_discarded', 'role_key' => null, 'payload' => ['glyph_key' => $glyphKey]]];

        [$state, $replaceEvents] = self::addGlyphOfType($state, $type);

        return [$state, [...$events, ...$replaceEvents]];
    }

    /**
     * Darkness into light: "🌿2 for all Wanderers. If any Wanderers are
     * on locations that produce 🌑, they 🌿7 instead."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicDarknessIntoLight(GameState $state): array
    {
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $location = LocationCatalog::find($state->board[Board::key($cell)]['location_key']);
            $amount = $location?->producesEmoji === '🌑' ? 7 : 2;

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + $amount);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Harbinger of death: "Every Wanderer loses 15 health. Trash this,
     * and gain 📜📜." Self-inflicted, not a Golem attack — none of the
     * "when the Golem 👊 you" passive reactions apply.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHarbingerOfDeath(GameState $state, string $actorRole): array
    {
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            [$state, $damageEvents] = self::applyDamage($state, $role, 15, false);
            $events = [...$events, ...$damageEvents];
        }

        $state = $state->with(['prophecy' => $state->prophecy + 2]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 2]];

        return [$state, $events];
    }

    /**
     * Golem counter: "If you are on the Golem's space: choose one of
     * the 3 prepared Golem cards, and tap it." Taps a single entry in
     * the revealed row ($golem['revealed']) rather than the draw pile —
     * same tapped flag, same "cannot be tapped" Enraged gate as
     * everything routed through tapGolemDrawPile, just applied to the
     * other array. $move['card_key'] picks which of the (at most 3)
     * revealed cards; a missing/already-tapped key is a no-op.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicGolemCounter(GameState $state, array $move): array
    {
        if (in_array('enraged', $state->golem['glyphs'], true)) {
            return [$state, []];
        }

        $cardKey = (string) ($move['card_key'] ?? '');
        $golem = $state->golem;

        foreach ($golem['revealed'] as $i => $entry) {
            if ($entry['key'] === $cardKey && ! $entry['tapped']) {
                $golem['revealed'][$i]['tapped'] = true;
                $state = $state->with(['golem' => $golem]);

                return [$state, [['type' => 'golem_card_flipped_tapped', 'role_key' => null, 'payload' => ['card_key' => $cardKey]]]];
            }
        }

        return [$state, []];
    }

    /**
     * Shroud of time: "Trash all Relics you have equipped, including
     * this. Replace each with a random Relic from its source deck (not
     * from the cards on Locations)." "Its source deck" is the shared,
     * global relicDecks pool keyed by branch emoji (symbol) — the same
     * pool Location-builds draw from, just drawn from directly here
     * instead of going through RelicDealer's per-Location allotment
     * rules. Handles its own trash (returns trash=false to the
     * dispatch table) since by the time the caller's own unconditional
     * trash-this-Relic step would run, this slot may already hold a
     * brand new replacement — trashEquippedRelic() keyed on the
     * original 'shroud-of-time' key would safely no-op by then, but
     * would still emit a spurious extra 'relic_trashed' event for it.
     * A branch with no Relics left in its deck just leaves that slot
     * empty.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicShroudOfTime(GameState $state, string $actorRole): array
    {
        $equipped = $state->wanderers[$actorRole]['equipped'];
        $relicDecks = $state->relicDecks;
        $trashedRelics = $state->trashedRelics;
        $events = [];

        foreach ($equipped as $slot => $relicKey) {
            if (! is_string($relicKey) || self::isStatusToken($relicKey)) {
                continue;
            }

            $relic = RelicCatalog::find($relicKey);
            $trashedRelics[] = $relicKey;
            $equipped[$slot] = null;
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]];

            $branch = $relic?->symbol;
            $deck = $branch !== null ? ($relicDecks[$branch] ?? []) : [];

            if ($deck !== []) {
                $index = array_rand($deck);
                $newRelicKey = $deck[$index];
                unset($deck[$index]);
                $relicDecks[$branch] = array_values($deck);
                $equipped[$slot] = $newRelicKey;
                $events[] = ['type' => 'relic_equipped', 'role_key' => $actorRole, 'payload' => ['relic_key' => $newRelicKey, 'slot' => $slot]];
            }
        }

        $state = $state->with([
            'wanderers' => $state->wanderersWith($actorRole, ['equipped' => $equipped]),
            'relicDecks' => $relicDecks,
            'trashedRelics' => $trashedRelics,
        ]);

        return [$state, $events];
    }

    /**
     * Emperor's Timepiece: "If you have all 5 Relics that begin with
     * 'Emperor's __' equipped, swap places with the Emperor, then trash
     * this and gain 📜. You are now the Emperor, and they are now a
     * Wanderer." The 5 named Relics (Emperor's Diary/Knowledge/Robes/
     * Staff/Timepiece) exactly match the 5 physical mat slots, so this
     * only ever fires with an otherwise-empty mat.
     *
     * The actual role swap is a human/seating concern (which player
     * controls the 'emperor' role vs this Wanderer role), not something
     * GameState itself models — that's GameRole's user_id<->role_key
     * assignment in the DB layer. This only validates the condition and
     * applies the card's own GameState-level effects (trash + Prophecy);
     * the emperor_timepiece_swap event tells the controller which two
     * roles to actually swap.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicEmperorsTimepiece(GameState $state, string $actorRole): array
    {
        $amount = self::countEmperorsRelics(array_values($state->wanderers[$actorRole]['equipped']));

        if ($amount < 5) {
            return [$state, [], false];
        }

        $state = $state->with(['prophecy' => $state->prophecy + 1]);

        return [$state, [
            ['type' => 'emperor_timepiece_swap', 'role_key' => $actorRole, 'payload' => ['new_emperor_role' => $actorRole, 'new_wanderer_role' => RoleKeys::EMPEROR]],
            ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]],
        ], true];
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicMove(GameState $state, string $actorRole, array $move, int $maxSteps): array
    {
        $directions = array_slice((array) ($move['directions'] ?? []), 0, $maxSteps);
        $events = [];

        foreach ($directions as $direction) {
            $wanderer = $state->wanderers[$actorRole];
            $to = Board::neighbor($wanderer['cell'], (string) $direction);

            if (! array_key_exists(Board::key($to), $state->board)) {
                break;
            }

            if (self::entryBlocked($state, $actorRole, $to)) {
                break;
            }

            $fromCell = $wanderer['cell'];
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['cell' => $to])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $to, 'free' => true]];

            [$state, $e] = self::moveDarkShacklesGolem($state, $actorRole, $fromCell, $to);
            $events = [...$events, ...$e];
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicSearchN(GameState $state, string $actorRole, int $count): array
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $wanderer = $state->wanderers[$actorRole];
            $cellKey = Board::key($wanderer['cell']);
            $pile = $state->board[$cellKey]['relics'];

            if ($pile === []) {
                break;
            }

            $index = array_rand($pile);
            $found = $pile[$index];
            unset($pile[$index]);
            $pile = array_values($pile);

            $foundRelic = RelicCatalog::find($found);
            $isEvent = $foundRelic !== null && $foundRelic->isEvent;

            $board = $state->board;
            $board[$cellKey] = [...$board[$cellKey], 'relics' => $pile];

            $wanderers = $state->wanderersWith($actorRole, [
                'holding' => $isEvent ? $wanderer['holding'] : [...$wanderer['holding'], $found],
            ]);

            $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);
            $events[] = ['type' => 'location_searched', 'role_key' => $actorRole, 'payload' => ['found' => $found, 'trashed_as_event' => $isEvent]];

            if ($isEvent) {
                [$state, $eventRelicEvents] = self::resolveEventRelic($state, $actorRole, $found, []);
                $events = [...$events, ...$eventRelicEvents];
            }
        }

        return [$state, $events];
    }

    /**
     * Executes an Event Relic's effect the instant it's found — rules.md:
     * "If an Event, resolve immediately and trash." Only a hand-picked
     * subset have real, executed behavior; everything else is logged
     * (via location_searched's trashed_as_event flag) but not applied,
     * same honesty pattern as every other unimplemented Relic/Golem
     * card. $move carries any extra choice a specific Event needs —
     * empty when resolved via a bonus search from another Relic's
     * effect rather than the top-level search_location move, which is
     * exactly why Invigoration/Rift in time/Epiphany are each a real
     * exception to "resolve immediately": their choice genuinely can't
     * be supplied in $move (the Wanderer doesn't know they found the
     * Relic until this move already returns), so each parks itself on
     * its own pendingXxx field instead of resolving here — see
     * offerInvigoration()/offerRiftInTime()/offerEpiphany() and their
     * matching resolveXxx() methods.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveEventRelic(GameState $state, string $actorRole, string $relicKey, array $move): array
    {
        return match ($relicKey) {
            'time-jump' => self::golemPlayCards($state, 1),
            'guilty-by-association', 'guilty-conscience' => self::applyShame($state, $actorRole),
            'sharp-objects' => self::applyBurn($state, $actorRole),
            'incarceration' => self::relicIncarceration($state, $actorRole),
            'buoyant-spirit' => self::relicActionDelta($state, $actorRole, 2),
            'lost-in-thought' => self::relicActionDelta($state, $actorRole, -1),
            'time-spasm' => self::relicTimeSpasm($state),
            'epiphany' => self::offerEpiphany($state, $actorRole),
            'golem-avarice' => self::relicGolemAvarice($state, $actorRole),
            'magic-explosion' => self::relicMagicExplosion($state, $actorRole),
            'a-rush-of-companionship' => self::relicRushOfCompanionship($state),
            'invigoration' => self::offerInvigoration($state, $actorRole),
            'rift-in-time' => self::offerRiftInTime($state, $actorRole),
            default => [$state, []],
        };
    }

    /**
     * Rift in time: "Choose a face-up Golem Glyph (if there are any),
     * discard it, and replace it with another Glyph of the same type."
     * Same can't-supply-the-choice-in-the-same-move problem as
     * Invigoration/Epiphany — parks the choice on pendingRiftInTime for
     * resolve_rift_in_time instead of resolving here. "If there are
     * any" — a genuinely empty Glyph row is a no-op right away, nothing
     * to offer a choice between.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function offerRiftInTime(GameState $state, string $actorRole): array
    {
        if ($state->golem['glyphs'] === []) {
            return [$state, []];
        }

        $state = $state->with(['pendingRiftInTime' => $actorRole]);

        return [$state, [['type' => 'rift_in_time_offered', 'role_key' => $actorRole, 'payload' => []]]];
    }

    /**
     * $move['glyph_key'] picks which currently-active Glyph; a missing
     * key or one not actually active is a no-op.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveRiftInTime(GameState $state, string $actorRole, array $move): array
    {
        if ($state->pendingRiftInTime !== $actorRole) {
            throw new InvalidMoveException('There is no Rift in time choice pending for you.');
        }

        $state = $state->with(['pendingRiftInTime' => null]);

        $glyphKey = (string) ($move['glyph_key'] ?? '');
        $glyphs = $state->golem['glyphs'];
        $index = array_search($glyphKey, $glyphs, true);

        if ($index === false) {
            return [$state, []];
        }

        $type = GlyphCatalog::find($glyphKey)?->type;

        if ($type === null) {
            return [$state, []];
        }

        unset($glyphs[$index]);
        $glyphs = array_values($glyphs);
        $glyphDiscardPile = [...$state->glyphDiscardPile, $glyphKey];
        $state = $state->with(['golem' => $state->golemWith(['glyphs' => $glyphs]), 'glyphDiscardPile' => $glyphDiscardPile]);

        $events = [['type' => 'glyph_discarded', 'role_key' => $actorRole, 'payload' => ['glyph_key' => $glyphKey]]];

        [$state, $replaceEvents] = self::addGlyphOfType($state, $type, $actorRole);

        return [$state, [...$events, ...$replaceEvents]];
    }

    /**
     * Incarceration: "You cannot move anymore this turn." Only blocks
     * move_wanderer specifically (not Search/Use-relic/etc.), via a new
     * per-Wanderer flag cleared when this Wanderer's next turn starts.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicIncarceration(GameState $state, string $actorRole): array
    {
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['move_blocked_this_turn' => true])]);

        return [$state, [['type' => 'wanderer_move_blocked', 'role_key' => $actorRole, 'payload' => []]]];
    }

    /**
     * Shared by Buoyant spirit (+2 actions) and Lost in thought (-1
     * action) — both just adjust the caster's actions_remaining for
     * the rest of this turn, floored at 0.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicActionDelta(GameState $state, string $actorRole, int $delta): array
    {
        $actions = max(0, $state->wanderers[$actorRole]['actions_remaining'] + $delta);
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['actions_remaining' => $actions])]);

        return [$state, [['type' => 'wanderer_actions_adjusted', 'role_key' => $actorRole, 'payload' => ['delta' => $delta, 'actions_remaining' => $actions]]]];
    }

    /**
     * Time spasm: "If the current round is an odd number, move the
     * round counter forward one round. If the current round is an
     * even number, move the tracker back one round."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicTimeSpasm(GameState $state): array
    {
        $newRound = $state->round % 2 === 1 ? $state->round + 1 : max(1, $state->round - 1);
        $state = $state->with(['round' => $newRound]);

        return [$state, [['type' => 'round_shifted', 'role_key' => null, 'payload' => ['round' => $newRound]]]];
    }

    /**
     * Epiphany: "Gain a face-down Relic on a different Location you
     * choose." Same can't-supply-the-choice-in-the-same-move problem
     * as Invigoration/Rift in time (the Wanderer doesn't know they
     * found this until the search_location move already returned) —
     * parks the choice on pendingEpiphany for resolve_epiphany instead
     * of resolving here.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function offerEpiphany(GameState $state, string $actorRole): array
    {
        $state = $state->with(['pendingEpiphany' => $actorRole]);

        return [$state, [['type' => 'epiphany_offered', 'role_key' => $actorRole, 'payload' => []]]];
    }

    /**
     * $move['cell'] picks which built, non-empty, non-caster Location
     * to pull a random face-down Relic from.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveEpiphany(GameState $state, string $actorRole, array $move): array
    {
        if ($state->pendingEpiphany !== $actorRole) {
            throw new InvalidMoveException('There is no Epiphany choice pending for you.');
        }

        $state = $state->with(['pendingEpiphany' => null]);

        $targetCell = $move['cell'] ?? null;

        if (! is_array($targetCell) || count($targetCell) !== 2) {
            return [$state, []];
        }

        $targetKey = Board::key([(int) $targetCell[0], (int) $targetCell[1]]);
        $casterCellKey = Board::key($state->wanderers[$actorRole]['cell']);

        if ($targetKey === $casterCellKey || ! array_key_exists($targetKey, $state->board)) {
            return [$state, []];
        }

        $pile = $state->board[$targetKey]['relics'];

        if ($pile === []) {
            return [$state, []];
        }

        $index = array_rand($pile);
        $found = $pile[$index];
        unset($pile[$index]);
        $pile = array_values($pile);

        $board = $state->board;
        $board[$targetKey] = [...$board[$targetKey], 'relics' => $pile];

        $foundRelic = RelicCatalog::find($found);
        $isEvent = $foundRelic !== null && $foundRelic->isEvent;

        $wanderer = $state->wanderers[$actorRole];
        $wanderers = $state->wanderersWith($actorRole, [
            'holding' => $isEvent ? $wanderer['holding'] : [...$wanderer['holding'], $found],
        ]);

        $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);
        $events = [['type' => 'location_searched', 'role_key' => $actorRole, 'payload' => ['found' => $found, 'trashed_as_event' => $isEvent]]];

        if ($isEvent) {
            [$state, $eventRelicEvents] = self::resolveEventRelic($state, $actorRole, $found, []);
            $events = [...$events, ...$eventRelicEvents];
        }

        return [$state, $events];
    }

    /**
     * Golem avarice: "Move all face-up and face-down Relics here to
     * the Golem's space. Place the face-down Relics underneath the
     * face-down Relics on the Golem's space, if there are any." Our
     * face-down pile has no top/bottom ordering (search draws
     * randomly), so "underneath" has no observable effect here —
     * the two piles are simply combined.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicGolemAvarice(GameState $state, string $actorRole): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $casterCellKey = Board::key($state->wanderers[$actorRole]['cell']);
        $golemCellKey = Board::key($golemCell);

        if ($casterCellKey === $golemCellKey) {
            return [$state, []];
        }

        $movedDown = $state->board[$casterCellKey]['relics'];
        $movedUp = $state->board[$casterCellKey]['relics_face_up'];
        $board = $state->board;
        $board[$casterCellKey] = [...$board[$casterCellKey], 'relics' => [], 'relics_face_up' => []];
        $board[$golemCellKey] = [
            ...$board[$golemCellKey],
            'relics' => [...$board[$golemCellKey]['relics'], ...$movedDown],
            'relics_face_up' => [...$board[$golemCellKey]['relics_face_up'], ...$movedUp],
        ];
        $state = $state->with(['board' => $board]);

        return [$state, [['type' => 'relics_moved_to_golem', 'role_key' => null, 'payload' => ['count' => count($movedDown) + count($movedUp)]]]];
    }

    /**
     * Magic explosion: "Move all face-up and face-down Relics here to
     * adjacent spaces, spread evenly. Then, place a Monolith Shard in
     * each space where a Relic landed." "Spread evenly" is read as
     * round-robin across the caster's occupied neighbors; a neighbor
     * only gets a Shard if the pool still has one and it doesn't
     * already have one (same 1-per-space rule as a normal placement).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicMagicExplosion(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $casterCellKey = Board::key($casterCell);
        $neighbors = Board::occupiedNeighbors($casterCell, $state->board);

        if ($neighbors === []) {
            return [$state, []];
        }

        $relicsDown = $state->board[$casterCellKey]['relics'];
        $relicsUp = $state->board[$casterCellKey]['relics_face_up'];
        $board = $state->board;
        $board[$casterCellKey] = [...$board[$casterCellKey], 'relics' => [], 'relics_face_up' => []];

        $landedKeys = [];
        foreach ($relicsDown as $i => $relicKey) {
            $neighborKey = Board::key($neighbors[$i % count($neighbors)]);
            $board[$neighborKey] = [...$board[$neighborKey], 'relics' => [...$board[$neighborKey]['relics'], $relicKey]];
            $landedKeys[$neighborKey] = true;
        }

        // Continue the round-robin offset from where the face-down pile
        // left off, so the two piles don't always both start on the same
        // first neighbor.
        foreach ($relicsUp as $i => $relicKey) {
            $neighborKey = Board::key($neighbors[(count($relicsDown) + $i) % count($neighbors)]);
            $board[$neighborKey] = [...$board[$neighborKey], 'relics_face_up' => [...$board[$neighborKey]['relics_face_up'], $relicKey]];
            $landedKeys[$neighborKey] = true;
        }

        $pool = $state->infrastructurePool;
        $events = [];

        foreach (array_keys($landedKeys) as $neighborKey) {
            if ($pool['monolith_shard'] <= 0) {
                break;
            }

            if (in_array('monolith_shard', $board[$neighborKey]['infrastructure'], true)) {
                continue;
            }

            $board[$neighborKey] = [...$board[$neighborKey], 'infrastructure' => [...$board[$neighborKey]['infrastructure'], 'monolith_shard']];
            $pool['monolith_shard']--;
            $events[] = ['type' => 'infrastructure_placed', 'role_key' => null, 'payload' => ['infrastructure' => 'monolith_shard', 'cell' => Board::fromKey($neighborKey)]];
        }

        $state = $state->with(['board' => $board, 'infrastructurePool' => $pool]);

        return [$state, $events];
    }

    /**
     * A rush of companionship: "All Wanderers may move to spaces with
     * other Wanderers. Then, all Wanderers on or adjacent to spaces
     * with other Wanderers 🌿5." The "may move" half is an optional,
     * per-Wanderer choice with no decision UI yet, so this resolves
     * only the heal, based on wherever everyone already happens to be
     * — the same kind of simplification as every other "Emperor/
     * Wanderer chooses" clause without a mid-phase pause system.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicRushOfCompanionship(GameState $state): array
    {
        $cells = self::activeWanderers($state);
        $sharedCellKeys = [];

        foreach ($cells as $role => $cell) {
            $cellKey = Board::key($cell);
            $sharedCellKeys[$cellKey] = ($sharedCellKeys[$cellKey] ?? 0) + 1;
        }
        $sharedCellKeys = array_keys(array_filter($sharedCellKeys, fn (int $n) => $n >= 2));

        $events = [];
        foreach ($cells as $role => $cell) {
            $onShared = in_array(Board::key($cell), $sharedCellKeys, true);
            $adjacentToShared = ! $onShared && array_intersect(
                array_map(Board::key(...), Board::occupiedNeighbors($cell, $state->board)),
                $sharedCellKeys
            ) !== [];

            if (! $onShared && ! $adjacentToShared) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 5);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Invigoration: "You may lose 7 Health in exchange for 2 extra
     * actions this round." The "may" is a real, immediate choice — but
     * unlike every other Event Relic, it can't be resolved in the same
     * move that found it, because the Wanderer doesn't know they found
     * it until the search_location move already returned. This parks
     * the decision on `pendingInvigoration` for a dedicated
     * resolve_invigoration move to pick up — see that method for the
     * actual effect.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function offerInvigoration(GameState $state, string $actorRole): array
    {
        $state = $state->with(['pendingInvigoration' => $actorRole]);

        return [$state, [['type' => 'invigoration_offered', 'role_key' => $actorRole, 'payload' => []]]];
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveInvigoration(GameState $state, string $actorRole, array $move): array
    {
        if ($state->pendingInvigoration !== $actorRole) {
            throw new InvalidMoveException('There is no Invigoration choice pending for you.');
        }

        $state = $state->with(['pendingInvigoration' => null]);

        if (($move['take'] ?? false) !== true) {
            return [$state, [['type' => 'invigoration_declined', 'role_key' => $actorRole, 'payload' => []]]];
        }

        $hp = max(0, $state->wanderers[$actorRole]['hp'] - 7);
        $actions = $state->wanderers[$actorRole]['actions_remaining'] + 2;
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp, 'actions_remaining' => $actions])]);

        return [$state, [
            ['type' => 'wanderer_damaged', 'role_key' => $actorRole, 'payload' => ['amount' => 7]],
            ['type' => 'wanderer_actions_adjusted', 'role_key' => $actorRole, 'payload' => ['delta' => 2, 'actions_remaining' => $actions]],
        ]];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHealBelowThreshold(GameState $state, int $threshold): array
    {
        $events = [];

        foreach ($state->wanderers as $role => $wanderer) {
            if ($wanderer['cell'] === null || $wanderer['hp'] >= $threshold) {
                continue;
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $threshold])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $threshold]];
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHealSharedCell(GameState $state, string $actorRole, int $amount): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) !== Board::key($casterCell)) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + $amount);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Divine healing oil: "🌿3 for yourself or an Ally on your space.
     * If your space produces 📖, 🌿6 instead."
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicDivineHealingOil(GameState $state, string $actorRole, array $move): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $location = LocationCatalog::find($state->board[Board::key($casterCell)]['location_key']);
        $amount = $location?->producesEmoji === '📖' ? 6 : 3;

        return self::relicHealSelfOrAlly($state, $actorRole, $move, $amount);
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHealSelfOrAlly(GameState $state, string $actorRole, array $move, int $amount): array
    {
        $targetRole = self::resolveAllyTarget($state, $actorRole, $move) ?? $actorRole;
        $hp = min(self::maxHpFor($state, $targetRole), $state->wanderers[$targetRole]['hp'] + $amount);
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['hp' => $hp])]);

        return [$state, [['type' => 'wanderer_healed', 'role_key' => $targetRole, 'payload' => ['hp' => $hp]]]];
    }

    /**
     * Last rites: "🌿 an Ally on your space to their maximum Health,
     * then trash this. If you healed 10+ Health, gain 📜." The
     * Prophecy clause was missing entirely — confirmed in prod: used
     * successfully, never granted it regardless of how much HP was
     * actually restored.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHealAllyToMax(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = self::resolveAllyTarget($state, $actorRole, $move);

        if ($targetRole === null) {
            return [$state, []];
        }

        $hpBefore = $state->wanderers[$targetRole]['hp'];
        $maxHp = self::maxHpFor($state, $targetRole);
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['hp' => $maxHp])]);

        $events = [['type' => 'wanderer_healed', 'role_key' => $targetRole, 'payload' => ['hp' => $maxHp]]];

        if ($maxHp - $hpBefore >= 10) {
            $state = $state->with(['prophecy' => $state->prophecy + 1]);
            $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];
        }

        return [$state, $events];
    }

    /**
     * $move['target_role'], if given, must be another Wanderer sharing
     * the actor's current space — an "Ally" per rules.md.
     *
     * @param  array<string, mixed>  $move
     */
    private static function resolveAllyTarget(GameState $state, string $actorRole, array $move): ?string
    {
        $targetRole = $move['target_role'] ?? null;

        if (! is_string($targetRole) || $targetRole === $actorRole || ! isset($state->wanderers[$targetRole])) {
            return null;
        }

        $actorCell = $state->wanderers[$actorRole]['cell'];
        $targetCell = $state->wanderers[$targetRole]['cell'];

        if ($actorCell === null || $targetCell === null || Board::key($actorCell) !== Board::key($targetCell)) {
            return null;
        }

        return $targetRole;
    }

    /**
     * Like resolveAllyTarget, but the caster targeting themselves is
     * allowed too — Hands of forgiveness says "Choose a Wanderer in your
     * space," not "an Ally."
     *
     * @param  array<string, mixed>  $move
     */
    private static function resolveSameSpaceTarget(GameState $state, string $actorRole, array $move): ?string
    {
        $targetRole = $move['target_role'] ?? null;

        if (! is_string($targetRole) || ! isset($state->wanderers[$targetRole])) {
            return null;
        }

        $actorCell = $state->wanderers[$actorRole]['cell'];
        $targetCell = $state->wanderers[$targetRole]['cell'];

        if ($actorCell === null || $targetCell === null || Board::key($actorCell) !== Board::key($targetCell)) {
            return null;
        }

        return $targetRole;
    }

    /**
     * Holy water: "Remove 1 Shame or 1 Burn from yourself or an Ally in
     * your space." $move['remove_type'] picks which; defaults to
     * removing Shame if present, else Burn.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicRemoveStatus(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = self::resolveSameSpaceTarget($state, $actorRole, $move) ?? $actorRole;

        $removeType = $move['remove_type'] ?? null;

        if (! in_array($removeType, ['shame_token', 'scar_token'], true)) {
            $removeType = $state->wanderers[$targetRole]['shame'] > 0 ? 'shame_token' : 'scar_token';
        }

        return $removeType === 'shame_token'
            ? self::removeShame($state, $targetRole)
            : self::removeBurn($state, $targetRole);
    }

    /**
     * Balm: "Remove 1 Scar from yourself, or an Ally on your space."
     * ("Scar" is leftover terminology from before the rules.md rename
     * to Burn — same mechanic as Holy water's own Burn-removal.)
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicBalm(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = self::resolveSameSpaceTarget($state, $actorRole, $move) ?? $actorRole;

        return self::removeBurn($state, $targetRole);
    }

    /**
     * Hands of forgiveness: "Choose a Wanderer in your space, and remove
     * all of their Shame and Burn tokens."
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicRemoveAllStatus(GameState $state, string $actorRole, array $move): array
    {
        // Defaults to the caster themselves when no target_role is
        // supplied, same as every other self-or-ally Relic (and its
        // sibling relicRemoveStatus/Holy water) — without this, the
        // plain "Use" button (no target) silently did nothing, and
        // self-targeting was only reachable via a "Use on {ally}"
        // button that, by definition, never offers yourself.
        $targetRole = self::resolveSameSpaceTarget($state, $actorRole, $move) ?? $actorRole;

        $events = [];
        [$state, $e] = self::removeShame($state, $targetRole, $state->wanderers[$targetRole]['shame']);
        $events = [...$events, ...$e];

        // A Wanderer could in principle hold more than one Burn across
        // different slots — repeat while any remain.
        while (in_array('burn_token', $state->wanderers[$targetRole]['equipped'], true)) {
            [$state, $e] = self::removeBurn($state, $targetRole);
            $events = [...$events, ...$e];
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicDiscardGolemTopCard(GameState $state): array
    {
        $golem = $state->golem;

        if ($golem['draw_pile'] === []) {
            return [$state, []];
        }

        $discarded = array_shift($golem['draw_pile']);
        $golem['discard_pile'][] = $discarded['key'];
        $state = $state->with(['golem' => $golem]);

        return [$state, [['type' => 'golem_card_discarded', 'role_key' => null, 'payload' => ['card_key' => $discarded['key']]]]];
    }

    /**
     * Flips the next $count untapped cards from the top of the Golem's
     * draw pile face-down, in place — several Relics do this (Blinding
     * explosion, Whip of Justice, Defanging tool, Golem log, Silent
     * hunting cloak, Peacemaker's spirit, Soothing Lullaby). Defanging
     * tool and Golem log additionally tuck the flipped cards into their
     * own per-Relic progress state, which doesn't exist yet — those two
     * remain unimplemented. Silent hunting cloak's printed text is
     * missing its verb (data issue, not a design question — see
     * rules-todo.md) and is also unimplemented pending a fix.
     *
     * Enraged Glyph: "Golem Cards cannot be tapped" — the single
     * chokepoint every tap effect routes through (plus Golem counter's
     * own revealed-row tap, gated the same way directly in
     * relicGolemCounter), so gating here covers every other Relic in
     * the list above for free.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: int} 2 = how many were actually tapped
     */
    private static function tapGolemDrawPile(GameState $state, int $count): array
    {
        if (in_array('enraged', $state->golem['glyphs'], true)) {
            return [$state, [], 0];
        }

        $golem = $state->golem;
        $tapped = 0;
        $events = [];

        foreach ($golem['draw_pile'] as $i => $entry) {
            if ($tapped >= $count) {
                break;
            }

            if ($entry['tapped']) {
                continue;
            }

            $golem['draw_pile'][$i]['tapped'] = true;
            $tapped++;
            $events[] = ['type' => 'golem_card_flipped_tapped', 'role_key' => null, 'payload' => ['card_key' => $entry['key']]];
        }

        $state = $state->with(['golem' => $golem]);

        return [$state, $events, $tapped];
    }

    /**
     * Whip of Justice: "If you are on the Golem's space: for each Shame
     * token the Golem has, tap the next un-tapped card from the top of
     * its draw pile, and trash this." The trashing is part of the
     * conditional clause — if the Wanderer isn't on the Golem's space,
     * nothing happens at all, including the trash. The "if you flipped 4
     * cards, gain 📜" clause is skipped, same as every other Relic's
     * scoring clause.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicTapForGolemShame(GameState $state, string $actorRole): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

        [$state, $events, $tapped] = self::tapGolemDrawPile($state, self::effectiveGolemShame($state));

        // "If you flipped 4 cards doing this, gain 📜."
        if ($tapped >= 4) {
            $state = $state->with(['prophecy' => $state->prophecy + 1]);
            $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];
        }

        return [$state, $events, true];
    }

    /**
     * Accused Glyph: "The Golem always has +1 Shame token. This does
     * not count toward its normal max of 3." A virtual bonus layered
     * on top of the stored golem['shame'] counter for every mechanic
     * that reads it as a quantity (Whip of Justice, Penitent, the
     * Prison-judgment Inscribe condition) — never stored back, and
     * never touched by anything that moves/spends actual Shame
     * (Magistrate's robes), since Accused's +1 isn't a real token.
     */
    private static function effectiveGolemShame(GameState $state): int
    {
        return $state->golem['shame'] + (in_array('accused', $state->golem['glyphs'], true) ? 1 : 0);
    }

    /**
     * Shared by any Relic whose text is "If you are in the Golem's space,
     * tap its next N untapped cards" with no other condition and no
     * trash (Peacemaker's spirit).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicTapGolemIfSharingCell(GameState $state, string $actorRole, int $count): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, []];
        }

        [$state, $events] = self::tapGolemDrawPile($state, $count);

        return [$state, $events];
    }

    /**
     * Blinding explosion: "All Wanderers on or adjacent to your space lose
     * 5 Health and gain a Burn token. If the Golem is in your space, tap
     * the next un-tapped 4 cards from their draw pile. Then, trash this,
     * and gain 📜." The damage and Burn always land; the tap is
     * conditional on the Golem sharing the caster's space; the trash
     * (like every "gain 📜" clause) is unconditional, same as a normal
     * one-shot Action Relic.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicBlindingExplosion(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $affected = [$casterCell, ...Board::occupiedNeighbors($casterCell, $state->board)];
        $affectedKeys = array_map(Board::key(...), $affected);

        // Captured before damage lands — a Wanderer this blast exhausts
        // still gets their Burn token; activeWanderers() would silently
        // drop them (it excludes 0 HP) if checked afterward instead.
        $affectedRoles = array_filter(
            array_keys(self::activeWanderers($state)),
            fn (string $role) => in_array(Board::key($state->wanderers[$role]['cell']), $affectedKeys, true),
        );

        [$state, $events] = self::damageWanderersAt($state, $affected, 5);

        foreach ($affectedRoles as $role) {
            [$state, $burnEvents] = self::applyBurn($state, $role);
            $events = [...$events, ...$burnEvents];
        }

        if (self::golemSharesCellWith($state, $actorRole)) {
            [$state, $tapEvents] = self::tapGolemDrawPile($state, 4);
            $events = [...$events, ...$tapEvents];
        }

        $state = $state->with(['prophecy' => $state->prophecy + 1]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];

        return [$state, $events, true];
    }

    /**
     * Soothing Lullaby: "If you are in the Golem's space and all 3 🎶
     * Relics [Lute, Pipe, Tabor drum] are equipped by Wanderers in your
     * space: tap the next 3 un-tapped cards of its draw pile. Then trash
     * this and gain 📜."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicSoothingLullaby(GameState $state, string $actorRole): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

        $casterCell = $state->wanderers[$actorRole]['cell'];
        $equippedInCasterSpace = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) === Board::key($casterCell)) {
                $equippedInCasterSpace = [...$equippedInCasterSpace, ...array_values($state->wanderers[$role]['equipped'])];
            }
        }

        $instruments = ['lute', 'pipe', 'tabor-drum'];
        $hasAllInstruments = count(array_intersect($instruments, $equippedInCasterSpace)) === count($instruments);

        if (! $hasAllInstruments) {
            return [$state, [], false];
        }

        [$state, $events] = self::tapGolemDrawPile($state, 3);

        $state = $state->with(['prophecy' => $state->prophecy + 1]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];

        return [$state, $events, true];
    }

    /**
     * Golem Prod: "If the Golem is in your space, you may move it to an
     * adjacent space." $move['direction'] picks which of the 6; a no-op
     * (not just skip) if the Golem isn't sharing the caster's cell, or
     * the chosen direction leads off the built map.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicGolemProd(GameState $state, string $actorRole, array $move): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, []];
        }

        $direction = (string) ($move['direction'] ?? '');
        $golemCell = $state->golem['cell'];
        $destination = Board::neighbor($golemCell, $direction);

        if (! array_key_exists(Board::key($destination), $state->board)) {
            return [$state, []];
        }

        if (in_array(Board::key($destination), $state->wallKeys(), true)) {
            return [$state, []];
        }

        $state = $state->with(['golem' => $state->golemWith(['cell' => $destination])]);
        $events = [['type' => 'golem_moved', 'role_key' => $actorRole, 'payload' => ['to' => $destination]]];

        [$state, $e] = self::moveDarkShacklesWanderers($state, $golemCell, $destination);
        $events = [...$events, ...$e];

        return [$state, $events];
    }

    /**
     * Golem intuition: "If you are on the Golem's space, look at the top
     * 3 cards of the Golem draw pile." Read-only — reveals the keys to
     * the acting player via the event payload, no state change.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicGolemIntuition(GameState $state, string $actorRole): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, []];
        }

        $topCards = array_map(fn (array $entry) => $entry['key'], array_slice($state->golem['draw_pile'], 0, 3));

        return [$state, [['type' => 'golem_draw_pile_revealed', 'role_key' => $actorRole, 'payload' => ['card_keys' => $topCards]]]];
    }

    /**
     * Accusing mind (key frozen as 'pointed-finger' from before this
     * Relic was renamed): "If you are on the Golem's space, add one
     * Shame token to the Golem, and one to yourself. Then, trash this,
     * and gain 📜." The Golem's own Shame counter has no printed cap
     * (unlike a Wanderer's, capped at 3) anywhere else in the engine,
     * so it's left uncapped here too.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicPointedFinger(GameState $state, string $actorRole): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

        $state = $state->with(['golem' => $state->golemWith(['shame' => $state->golem['shame'] + 1])]);
        $events = [['type' => 'golem_shame_gained', 'role_key' => null, 'payload' => ['amount' => 1]]];

        [$state, $e] = self::applyShame($state, $actorRole);
        $events = [...$events, ...$e];

        $state = $state->with(['prophecy' => $state->prophecy + 1]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];

        return [$state, $events, true];
    }

    /**
     * Divine aura: "If you are on a space with a Monolith Shard, 🌿3 for
     * all Wanderers on or adjacent to your space. If the space produces
     * 📖, 🌿6 instead."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicDivineAura(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];

        if (! self::hasMonolithShardFor($state, $actorRole, $casterCell)) {
            return [$state, []];
        }

        $cellKeys = [Board::key($casterCell), ...array_map(Board::key(...), Board::occupiedNeighbors($casterCell, $state->board))];
        $casterLocation = LocationCatalog::find($state->board[Board::key($casterCell)]['location_key']);
        $amount = $casterLocation?->producesEmoji === '📖' ? 6 : 3;
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + $amount);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Emperor's Staff: "If your space does not have a Monolith Shard,
     * create one. If you are on a space that produces ⏳, +1 action."
     * Draws from the Emperor's fixed Infrastructure pool like a normal
     * placement would — a no-op if the pool is empty, since there's no
     * other source of Monolith Shards in rules.md. The +1 action is
     * unconditional on the ⏳ space regardless of whether a Shard was
     * actually created.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicEmperorsStaff(GameState $state, string $actorRole): array
    {
        $cellKey = Board::key($state->wanderers[$actorRole]['cell']);
        $entry = $state->board[$cellKey];
        $events = [];

        if (! in_array('monolith_shard', $entry['infrastructure'], true) && ($state->infrastructurePool['monolith_shard'] ?? 0) > 0) {
            $board = $state->board;
            $board[$cellKey] = [...$entry, 'infrastructure' => [...$entry['infrastructure'], 'monolith_shard']];
            $pool = $state->infrastructurePool;
            $pool['monolith_shard']--;
            $state = $state->with(['board' => $board, 'infrastructurePool' => $pool]);
            $events[] = ['type' => 'infrastructure_placed', 'role_key' => $actorRole, 'payload' => ['infrastructure' => 'monolith_shard', 'cell' => $state->wanderers[$actorRole]['cell']]];
        }

        // "If you are on a space that produces ⏳, +1 action."
        $location = LocationCatalog::find($entry['location_key']);

        if ($location?->producesEmoji === '⏳') {
            $wanderer = $state->wanderers[$actorRole];
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['actions_remaining' => $wanderer['actions_remaining'] + 1])]);
        }

        return [$state, $events];
    }

    /**
     * Ban of dangerous ideas: "Any Wanderer or Golem on a space that
     * produces 📖, 💭, 🎨, or 🪙 gains 1 Shame token (max 3 Shame
     * tokens). Then, trash this." The Golem has no equip slots to hold a
     * Shame token in, so (like Accusing mind) its Shame is tracked on
     * its own uncapped counter — this card's printed cap of 3 only binds
     * the comparison for symmetry with the Wanderer side, since nothing
     * else in rules.md caps the Golem's Shame today.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicBanOfDangerousIdeas(GameState $state): array
    {
        $branches = ['📖', '💭', '🎨', '🪙'];
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $location = LocationCatalog::find($state->board[Board::key($cell)]['location_key']);

            if ($location === null || ! in_array($location->producesEmoji, $branches, true)) {
                continue;
            }

            [$state, $e] = self::applyShame($state, $role);
            $events = [...$events, ...$e];
        }

        $golemCell = $state->golem['cell'];

        if ($golemCell !== null) {
            $location = LocationCatalog::find($state->board[Board::key($golemCell)]['location_key']);

            if ($location !== null && in_array($location->producesEmoji, $branches, true) && $state->golem['shame'] < 3) {
                $state = $state->with(['golem' => $state->golemWith(['shame' => $state->golem['shame'] + 1])]);
                $events[] = ['type' => 'golem_shame_gained', 'role_key' => null, 'payload' => ['amount' => 1]];
            }
        }

        return [$state, $events];
    }

    /**
     * Writ of solitude: "Any Wanderer or Golem who is on a space with a
     * Wanderer gains 1 Shame token (max 3 Shame tokens). Then, trash
     * this." A Wanderer alone on their own space doesn't trigger it —
     * they need company (another Wanderer, or the Golem sharing a
     * Wanderer's space triggers the Golem's own gain).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicWritOfSolitude(GameState $state): array
    {
        $byCell = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $byCell[Board::key($cell)][] = $role;
        }

        $events = [];

        foreach ($byCell as $roles) {
            if (count($roles) < 2) {
                continue;
            }

            foreach ($roles as $role) {
                [$state, $e] = self::applyShame($state, $role);
                $events = [...$events, ...$e];
            }
        }

        $golemCell = $state->golem['cell'];

        if ($golemCell !== null && isset($byCell[Board::key($golemCell)]) && $state->golem['shame'] < 3) {
            $state = $state->with(['golem' => $state->golemWith(['shame' => $state->golem['shame'] + 1])]);
            $events[] = ['type' => 'golem_shame_gained', 'role_key' => null, 'payload' => ['amount' => 1]];
        }

        return [$state, $events];
    }

    /**
     * Teleportation stone: "Move an Ally to any space that contains a
     * Monolith Shard, if they are allowed to be there." $move
     * ['target_role'] must be an Ally (sharing the caster's space, same
     * definition used everywhere else); $move['cell'] must be a built
     * space with a Monolith Shard on it.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicTeleportationStone(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = self::resolveAllyTarget($state, $actorRole, $move);

        if ($targetRole === null) {
            return [$state, []];
        }

        $destination = $move['cell'] ?? null;

        if (! is_array($destination) || count($destination) !== 2) {
            return [$state, []];
        }

        $destination = [(int) $destination[0], (int) $destination[1]];
        $destinationKey = Board::key($destination);

        if (! array_key_exists($destinationKey, $state->board)) {
            return [$state, []];
        }

        if (! in_array('monolith_shard', $state->board[$destinationKey]['infrastructure'], true)) {
            return [$state, []];
        }

        if (self::entryBlocked($state, $targetRole, $destination)) {
            return [$state, []];
        }

        $fromCell = $state->wanderers[$targetRole]['cell'];
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['cell' => $destination])]);
        $events = [['type' => 'wanderer_moved', 'role_key' => $targetRole, 'payload' => ['to' => $destination]]];

        [$state, $e] = self::moveDarkShacklesGolem($state, $targetRole, $fromCell, $destination);
        $events = [...$events, ...$e];

        return [$state, $events];
    }

    /**
     * Hands of deliverance: "Bound. Move 1 face-up relic from any space
     * to any other space." $move['from_cell']/['to_cell'] pick the two
     * spaces, $move['board_relic_key'] which one in the source pile
     * (not $move['relic_key'] — that's already taken by useRelic's own
     * "which equipped Relic am I using" field).
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHandsOfDeliverance(GameState $state, array $move): array
    {
        $fromCell = $move['from_cell'] ?? null;
        $toCell = $move['to_cell'] ?? null;
        $relicKey = (string) ($move['board_relic_key'] ?? '');

        if (! is_array($fromCell) || count($fromCell) !== 2 || ! is_array($toCell) || count($toCell) !== 2) {
            return [$state, []];
        }

        $fromKey = Board::key([(int) $fromCell[0], (int) $fromCell[1]]);
        $toKey = Board::key([(int) $toCell[0], (int) $toCell[1]]);

        if (! array_key_exists($fromKey, $state->board) || ! array_key_exists($toKey, $state->board)) {
            return [$state, []];
        }

        $fromEntry = $state->board[$fromKey];
        $index = array_search($relicKey, $fromEntry['relics_face_up'], true);

        if ($index === false) {
            return [$state, []];
        }

        $fromRelics = $fromEntry['relics_face_up'];
        unset($fromRelics[$index]);
        $fromRelics = array_values($fromRelics);

        $board = $state->board;
        $board[$fromKey] = [...$fromEntry, 'relics_face_up' => $fromRelics];
        $board[$toKey] = [...$board[$toKey], 'relics_face_up' => [...$board[$toKey]['relics_face_up'], $relicKey]];
        $state = $state->with(['board' => $board]);

        return [$state, [['type' => 'relic_moved', 'role_key' => null, 'payload' => ['relic_key' => $relicKey, 'from' => $fromKey, 'to' => $toKey]]]];
    }

    /**
     * Hands of transport: "Choose two spaces that have Monolith
     * Shards. Move all the Relics from one space to the other. If you
     * combined sets of face-down Relics doing this, shuffle them."
     * Moves the face-down pile (board[cell]['relics'], not the
     * face-up/dropped one Hands of deliverance moves) — only shuffled
     * if the destination already had Relics of its own to combine
     * with; moving into an empty pile keeps the source's own order.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHandsOfTransport(GameState $state, array $move): array
    {
        $fromCell = $move['from_cell'] ?? null;
        $toCell = $move['to_cell'] ?? null;

        if (! is_array($fromCell) || count($fromCell) !== 2 || ! is_array($toCell) || count($toCell) !== 2) {
            return [$state, []];
        }

        $fromKey = Board::key([(int) $fromCell[0], (int) $fromCell[1]]);
        $toKey = Board::key([(int) $toCell[0], (int) $toCell[1]]);

        if (! array_key_exists($fromKey, $state->board) || ! array_key_exists($toKey, $state->board) || $fromKey === $toKey) {
            return [$state, []];
        }

        if (! in_array('monolith_shard', $state->board[$fromKey]['infrastructure'], true)
            || ! in_array('monolith_shard', $state->board[$toKey]['infrastructure'], true)) {
            return [$state, []];
        }

        $board = $state->board;
        $moving = $board[$fromKey]['relics'];

        if ($moving === []) {
            return [$state, []];
        }

        $destinationHadRelics = $board[$toKey]['relics'] !== [];
        $combined = [...$board[$toKey]['relics'], ...$moving];

        if ($destinationHadRelics) {
            shuffle($combined);
        }

        $board[$fromKey] = [...$board[$fromKey], 'relics' => []];
        $board[$toKey] = [...$board[$toKey], 'relics' => $combined];
        $state = $state->with(['board' => $board]);

        return [$state, [['type' => 'relics_moved', 'role_key' => null, 'payload' => ['count' => count($moving), 'from' => $fromKey, 'to' => $toKey]]]];
    }

    /**
     * Divine aura eruption: "If there are 7+ Monolith Shards on or
     * adjacent to your space, and your space produces 📖, all
     * Wanderers: 🌿15, remove all Burns, remove all Shame. Trash this
     * and gain 📜."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicAuraEruption(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];

        if ($casterCell === null || self::monolithShardsWithin($state, $casterCell, 1) < 7) {
            return [$state, [], false];
        }

        $casterLocation = LocationCatalog::find($state->board[Board::key($casterCell)]['location_key']);

        if ($casterLocation?->producesEmoji !== '📖') {
            return [$state, [], false];
        }

        $events = [];

        // "All Wanderers" here deliberately means every Wanderer, not
        // just activeWanderers() — an exhausted (0 HP) Wanderer is
        // exactly who most needs this heal, not someone to skip.
        foreach ($state->wanderers as $role => $wanderer) {
            if ($wanderer['cell'] === null) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 15);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];

            while (in_array('burn_token', $state->wanderers[$role]['equipped'], true)) {
                [$state, $e] = self::removeBurn($state, $role);
                $events = [...$events, ...$e];
            }

            if ($state->wanderers[$role]['shame'] > 0) {
                [$state, $e] = self::removeShame($state, $role, $state->wanderers[$role]['shame']);
                $events = [...$events, ...$e];
            }
        }

        $state = $state->with(['prophecy' => $state->prophecy + 1]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];

        return [$state, $events, true];
    }

    /**
     * Divine robes: "When on a space that produces 📖, 🌿5 for yourself
     * and all Allies, regardless of distance." "Regardless of distance"
     * means every active Wanderer, not just those sharing the caster's
     * space.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicDivineRobes(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $location = LocationCatalog::find($state->board[Board::key($casterCell)]['location_key']);

        if ($location === null || $location->producesEmoji !== '📖') {
            return [$state, []];
        }

        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 5);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Divine solidarity: "If you are on a space with an Ally, 🌿4 for
     * you and your Ally. If your space produces 📖, 🌿8 instead." Heals
     * every Wanderer sharing the caster's space (including the caster)
     * rather than requiring a single chosen target, since the printed
     * text doesn't single one out when more than one Ally is present.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicDivineSolidarity(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $casterCellKey = Board::key($casterCell);

        $hasAlly = false;
        foreach (self::activeWanderers($state) as $role => $cell) {
            if ($role !== $actorRole && Board::key($cell) === $casterCellKey) {
                $hasAlly = true;
                break;
            }
        }

        if (! $hasAlly) {
            return [$state, []];
        }

        $location = LocationCatalog::find($state->board[$casterCellKey]['location_key']);
        $amount = $location?->producesEmoji === '📖' ? 8 : 4;

        $events = [];
        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) !== $casterCellKey) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + $amount);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Warlock's robes: "Choose an Ally, and do one of the following.
     * Either: They perform the action from one of your other Relics, or
     * you perform the actions from one of their Relics." Borrows
     * resolveRelicAction for the chosen Relic, run as if the performer
     * used it normally (including its own trash clause, applied to
     * whichever of the two actually owns/equips it) — but without
     * spending an extra action, since that was already paid for
     * Warlock's robes itself. $move['mode'] is 'ally_uses_mine' or
     * 'i_use_theirs'; $move['other_relic_key'] is which of the owner's
     * equipped Relics to borrow. Chaining into another Warlock's robes
     * is blocked to avoid recursion.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicWarlocksRobes(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = self::resolveAllyTarget($state, $actorRole, $move);

        if ($targetRole === null) {
            return [$state, []];
        }

        $mode = (string) ($move['mode'] ?? '');
        $otherRelicKey = (string) ($move['other_relic_key'] ?? '');

        [$ownerRole, $performerRole] = match ($mode) {
            'ally_uses_mine' => [$actorRole, $targetRole],
            'i_use_theirs' => [$targetRole, $actorRole],
            default => [null, null],
        };

        if ($ownerRole === null || ! in_array($otherRelicKey, $state->wanderers[$ownerRole]['equipped'], true)) {
            return [$state, []];
        }

        $otherBaseKey = (string) preg_replace('/-\d+$/', '', $otherRelicKey);

        if ($otherBaseKey === 'warlocks-robes') {
            return [$state, []];
        }

        [$state, $events, $trash] = self::resolveRelicAction($state, $performerRole, $otherBaseKey, $otherRelicKey, $move);

        if ($trash) {
            $state = self::trashEquippedRelic($state, $ownerRole, $otherRelicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $ownerRole, 'payload' => ['relic_key' => $otherRelicKey]];
        }

        return [$state, $events];
    }

    /**
     * Relics named "Emperor's __" — counted for Emperor's Diary/Robes.
     *
     * @param  array<mixed>  $relicKeys
     */
    private static function countEmperorsRelics(array $relicKeys): int
    {
        $count = 0;

        foreach ($relicKeys as $relicKey) {
            if (! is_string($relicKey)) {
                continue;
            }

            $relic = RelicCatalog::find($relicKey);

            if ($relic !== null && str_starts_with($relic->name, "Emperor's")) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Emperor's Diary: "🔎1. Find +1 Relic for every 3 Relics you have
     * that start with 'Emperor's __'." Counts both equipped and held
     * Relics as "Relics you have."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicEmperorsDiary(GameState $state, string $actorRole): array
    {
        $wanderer = $state->wanderers[$actorRole];
        $haveCount = self::countEmperorsRelics([...array_values($wanderer['equipped']), ...$wanderer['holding']]);

        return self::relicSearchN($state, $actorRole, 1 + intdiv($haveCount, 3));
    }

    /**
     * Emperor's Robes: "🌿1 yourself or an Ally on your space, for each
     * Relic you have equipped that starts with 'Emperor's __.' If you
     * are on a space with a Monolith Shard, +2🌿." Only equipped Relics
     * count here (unlike Emperor's Diary, which also counts held ones)
     * — that's what the card actually says.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicEmperorsRobes(GameState $state, string $actorRole, array $move): array
    {
        $amount = self::countEmperorsRelics(array_values($state->wanderers[$actorRole]['equipped']));

        if ($amount === 0) {
            return [$state, []];
        }

        if (self::hasMonolithShardFor($state, $actorRole, $state->wanderers[$actorRole]['cell'])) {
            $amount += 2;
        }

        $targetRole = self::resolveAllyTarget($state, $actorRole, $move) ?? $actorRole;
        $hp = min(self::maxHpFor($state, $targetRole), $state->wanderers[$targetRole]['hp'] + $amount);
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['hp' => $hp])]);

        return [$state, [['type' => 'wanderer_healed', 'role_key' => $targetRole, 'payload' => ['hp' => $hp]]]];
    }

    /**
     * An insight from the future: "Search the Emperor's draw pile, and
     * place one card into the Emperor's hand. Shuffle their draw pile.
     * Then, trash this, and gain 📜." $move['location_key'] picks which
     * card from the (hidden-information) Location deck to pull —
     * there's no UI for "search a hidden pile" yet, so the caller must
     * already know what's in it (e.g. a debug/test context); a missing
     * or absent key is a no-op.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicInsightFromTheFuture(GameState $state, array $move): array
    {
        $locationKey = (string) ($move['location_key'] ?? '');
        $deck = $state->locationDeck;
        $index = array_search($locationKey, $deck, true);

        if ($index === false) {
            return [$state, [], false];
        }

        unset($deck[$index]);
        $deck = array_values($deck);
        shuffle($deck);

        $hand = [...$state->locationHand, $locationKey];
        $state = $state->with(['locationDeck' => $deck, 'locationHand' => $hand, 'prophecy' => $state->prophecy + 1]);

        return [$state, [
            ['type' => 'location_drawn', 'role_key' => null, 'payload' => ['location_key' => $locationKey]],
            ['type' => 'prophecy_gained', 'role_key' => null, 'payload' => ['amount' => 1, 'reason' => 'an-insight-from-the-future']],
        ], true];
    }

    /**
     * Aural inscription: "If all Wanderers are adjacent to the Golem
     * but each on their own space, and all 3 🎶 Relics are equipped by
     * Wanderers: gain 📜, and add a 🎨 Glyph to the Golem's mat. Then,
     * trash this." Unlike Soothing Lullaby, the instruments just need
     * to be equipped by Wanderers somewhere — not all in one space.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicAuralInscription(GameState $state, string $actorRole): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, [], false];
        }

        $cells = self::activeWanderers($state);
        $seenCellKeys = [];
        $equippedAnywhere = [];

        foreach ($cells as $role => $cell) {
            if (Board::distance($golemCell, $cell, $state->board) !== 1) {
                return [$state, [], false];
            }

            $cellKey = Board::key($cell);

            if (isset($seenCellKeys[$cellKey])) {
                return [$state, [], false];
            }

            $seenCellKeys[$cellKey] = true;
            $equippedAnywhere = [...$equippedAnywhere, ...array_values($state->wanderers[$role]['equipped'])];
        }

        $instruments = ['lute', 'pipe', 'tabor-drum'];

        if (count(array_intersect($instruments, $equippedAnywhere)) !== count($instruments)) {
            return [$state, [], false];
        }

        return self::inscribeOutcome($state, '🎨', $actorRole, 1, true);
    }

    /**
     * Divine Chant: "If you are on the Cathedral, the Golem 2+ spaces
     * away, and all 3 🎶 Relics are equipped by Wanderers in your
     * space: 🌿 all Wanderers to full health, then trash this and
     * gain 📜."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicDivineChant(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $golemCell = $state->golem['cell'];

        if ($state->board[Board::key($casterCell)]['location_key'] !== 'cathedral') {
            return [$state, [], false];
        }

        if ($golemCell === null || (Board::distance($casterCell, $golemCell, $state->board) ?? 0) < 2) {
            return [$state, [], false];
        }

        $equippedInCasterSpace = [];
        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) === Board::key($casterCell)) {
                $equippedInCasterSpace = [...$equippedInCasterSpace, ...array_values($state->wanderers[$role]['equipped'])];
            }
        }

        $instruments = ['lute', 'pipe', 'tabor-drum'];

        if (count(array_intersect($instruments, $equippedInCasterSpace)) !== count($instruments)) {
            return [$state, [], false];
        }

        $events = [];
        foreach (self::activeWanderers($state) as $role => $cell) {
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => 15])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => 15]];
        }

        $state = $state->with(['prophecy' => $state->prophecy + 1]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];

        return [$state, $events, true];
    }

    /**
     * Magistrate's robes: "If you are on a space that produces ⚖️, move
     * 2 Shame tokens from any Wanderer or Golem to any other Wanderer
     * or Golem. Maximum 3 Shame tokens, as usual." $move['from_role']/
     * ['to_role'] are either a Wanderer role key or the literal string
     * 'golem'. Moves as many of the 2 as the source actually has and
     * the destination has room for (capped at 3).
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicMagistratesRobes(GameState $state, string $actorRole, array $move): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $location = LocationCatalog::find($state->board[Board::key($casterCell)]['location_key']);

        if ($location === null || $location->producesEmoji !== '⚖️') {
            return [$state, []];
        }

        $fromRole = (string) ($move['from_role'] ?? '');
        $toRole = (string) ($move['to_role'] ?? '');

        if ($fromRole === '' || $toRole === '' || $fromRole === $toRole) {
            return [$state, []];
        }

        if ($fromRole !== 'golem' && ! isset($state->wanderers[$fromRole])) {
            return [$state, []];
        }

        if ($toRole !== 'golem' && ! isset($state->wanderers[$toRole])) {
            return [$state, []];
        }

        $events = [];

        for ($i = 0; $i < 2; $i++) {
            $available = $fromRole === 'golem'
                ? $state->golem['shame'] > 0
                : $state->wanderers[$fromRole]['shame'] > 0;

            $room = $toRole === 'golem'
                ? $state->golem['shame'] < 3
                : $state->wanderers[$toRole]['shame'] < 3;

            if (! $available || ! $room) {
                break;
            }

            if ($fromRole === 'golem') {
                $state = $state->with(['golem' => $state->golemWith(['shame' => $state->golem['shame'] - 1])]);
            } else {
                [$state, $e] = self::removeShame($state, $fromRole);
                $events = [...$events, ...$e];
            }

            if ($toRole === 'golem') {
                $state = $state->with(['golem' => $state->golemWith(['shame' => $state->golem['shame'] + 1])]);
            } else {
                [$state, $e] = self::applyShame($state, $toRole);
                $events = [...$events, ...$e];
            }

            $events[] = ['type' => 'shame_moved', 'role_key' => null, 'payload' => ['from' => $fromRole, 'to' => $toRole]];
        }

        return [$state, $events];
    }

    /**
     * Immolation rags: "Trash all Relics on your mat (including this,
     * and any Bound Relics). For each Relic you trashed: You lose 1
     * Health." Handles its own full trash (including itself) rather
     * than returning a trash flag, since useRelic's normal post-dispatch
     * trash only clears the one Relic being used.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicImmolationRags(GameState $state, string $actorRole): array
    {
        $trashedKeys = [];

        foreach ($state->wanderers[$actorRole]['equipped'] as $value) {
            if ($value !== null && ! self::isStatusToken($value)) {
                $trashedKeys[] = $value;
                $state = self::trashEquippedRelic($state, $actorRole, $value);
            }
        }

        $events = array_map(
            fn (string $key) => ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $key]],
            $trashedKeys
        );

        $count = count($trashedKeys);
        $hp = max(0, $state->wanderers[$actorRole]['hp'] - $count);
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
        $events[] = ['type' => 'wanderer_damaged', 'role_key' => $actorRole, 'payload' => ['amount' => $count]];

        // "If you trashed 5 Relics doing this, gain 📜."
        if ($count >= 5) {
            $state = $state->with(['prophecy' => $state->prophecy + 1]);
            $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];
        }

        return [$state, $events, false];
    }

    /**
     * Divine Cabalist pyre: "Trash any number of face-up Relics on your
     * space, and trash this. For you and any other Wanderers on your
     * space: gain a Burn token, then 🌿2 for each Relic you trashed."
     * $move['board_relic_keys'] picks which (any number, including
     * zero) from the caster's own cell's face-up pile.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicDivineCabalistPyre(GameState $state, string $actorRole, array $move): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $cellKey = Board::key($casterCell);
        $chosen = $move['board_relic_keys'] ?? [];

        if (! is_array($chosen)) {
            return [$state, [], true];
        }

        $pile = $state->board[$cellKey]['relics_face_up'];
        $burned = [];

        foreach ($chosen as $relicKey) {
            if (! is_string($relicKey)) {
                continue;
            }

            $index = array_search($relicKey, $pile, true);

            if ($index === false) {
                continue;
            }

            unset($pile[$index]);
            $pile = array_values($pile);
            $burned[] = $relicKey;
        }

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'relics_face_up' => $pile];
        $state = $state->with(['board' => $board]);

        $events = array_map(
            fn (string $key) => ['type' => 'relic_burned', 'role_key' => null, 'payload' => ['relic_key' => $key, 'cell' => $casterCell]],
            $burned
        );

        $healAmount = 2 * count($burned);

        // "For you and any other Wanderers on your space: gain a Burn
        // token, then 🌿2 for each Relic you trashed." Every Wanderer
        // sharing the caster's cell gets a Burn token unconditionally
        // (even if 0 Relics were trashed); the heal itself is naturally
        // 0 in that case, so no event is emitted for it.
        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) !== $cellKey) {
                continue;
            }

            [$state, $burnEvents] = self::applyBurn($state, $role);
            $events = [...$events, ...$burnEvents];

            if ($healAmount > 0) {
                $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + $healAmount);
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
                $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
            }
        }

        // "If you burn 10 Relics doing this, gain 📜📜."
        if (count($burned) >= 10) {
            $state = $state->with(['prophecy' => $state->prophecy + 2]);
            $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 2]];
        }

        return [$state, $events, true];
    }

    /**
     * Armistice: "Wanderers on the Golem's space may trash Relics
     * from their mats (you must trash this card too). For every 4
     * Relics trashed, trash 1 card from the Golem discard pile, and
     * gain 📜. Shuffle the Golem draw pile and discard pile."
     * $move['trashed'] is a list of {role, relic_key} for every
     * voluntary trash beyond Armistice's own mandatory one; each
     * entry must name an active Wanderer sharing the Golem's space with
     * that Relic actually equipped.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicArmistice(GameState $state, string $actorRole, array $move): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

        $golemCell = $state->golem['cell'];
        $golemCellKey = $golemCell === null ? null : Board::key($golemCell);
        $requested = $move['trashed'] ?? [];
        $events = [];
        $trashedCount = 1; // Armistice itself, trashed by the normal post-dispatch flow.

        if (is_array($requested)) {
            foreach ($requested as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $role = $entry['role'] ?? null;
                $relicKey = $entry['relic_key'] ?? null;

                if (! is_string($role) || ! is_string($relicKey) || ! isset($state->wanderers[$role])) {
                    continue;
                }

                $wandererCell = $state->wanderers[$role]['cell'];

                if ($wandererCell === null || Board::key($wandererCell) !== $golemCellKey) {
                    continue;
                }

                if (self::isStatusToken($relicKey) || ! in_array($relicKey, $state->wanderers[$role]['equipped'], true)) {
                    continue;
                }

                $state = self::trashEquippedRelic($state, $role, $relicKey);
                $events[] = ['type' => 'relic_trashed', 'role_key' => $role, 'payload' => ['relic_key' => $relicKey]];
                $trashedCount++;
            }
        }

        $groupsOf4 = intdiv($trashedCount, 4);

        if ($groupsOf4 > 0) {
            $discardPile = $state->golem['discard_pile'];
            $removed = array_splice($discardPile, 0, min($groupsOf4, count($discardPile)));
            $state = $state->with(['golem' => $state->golemWith(['discard_pile' => $discardPile])]);

            foreach ($removed as $cardKey) {
                $events[] = ['type' => 'golem_card_removed_from_discard', 'role_key' => null, 'payload' => ['card_key' => $cardKey]];
            }

            // "For every 4 Relics trashed, trash 1 card from the Golem
            // discard pile, and gain 📜." — 1 Prophecy per group of 4,
            // not per card actually removed (the discard pile can run
            // out of cards to remove before the groups do).
            $state = $state->with(['prophecy' => $state->prophecy + $groupsOf4]);
            $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => $groupsOf4]];
        }

        $drawPile = $state->golem['draw_pile'];
        shuffle($drawPile);
        $discardPile = $state->golem['discard_pile'];
        shuffle($discardPile);
        $state = $state->with(['golem' => $state->golemWith(['draw_pile' => $drawPile, 'discard_pile' => $discardPile])]);

        return [$state, $events, true];
    }

    /**
     * Cabalist brand: "If you are on the Golem's space, discard any
     * Glyphs on its mat you choose, gain a Burn token, trash this, and
     * gain 📜. Then, the Golem plays 3 cards." $move['glyph_keys'] picks
     * which (any number, including zero) of the Golem's active Glyphs
     * to discard before it plays. Reuses golemPlayCards so "plays 3
     * cards" is identical to 3 plays during a normal Golem phase —
     * including Double Down/Time Paradox if drawn.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicCabalistBrand(GameState $state, string $actorRole, array $move): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

        $chosen = $move['glyph_keys'] ?? [];
        $events = [];

        if (is_array($chosen)) {
            $glyphs = $state->golem['glyphs'];

            foreach ($chosen as $glyphKey) {
                if (! is_string($glyphKey)) {
                    continue;
                }

                $index = array_search($glyphKey, $glyphs, true);

                if ($index === false) {
                    continue;
                }

                unset($glyphs[$index]);
                $glyphs = array_values($glyphs);
                $events[] = ['type' => 'glyph_discarded', 'role_key' => null, 'payload' => ['glyph_key' => $glyphKey]];
            }

            $state = $state->with(['golem' => $state->golemWith(['glyphs' => $glyphs])]);
        }

        [$state, $burnEvents] = self::applyBurn($state, $actorRole);
        $events = [...$events, ...$burnEvents];

        $state = $state->with(['prophecy' => $state->prophecy + 1]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];

        [$state, $cardEvents] = self::golemPlayCards($state, 3);
        $events = [...$events, ...$cardEvents];

        return [$state, $events, true];
    }

    /**
     * Poof: "If there are 12 Monolith shards within 2 spaces of you:
     * remove the Golem from the board, trash this, and gain 📜📜📜. Skip
     * the Golem phase for the rest of the game." 12 is the entire fixed
     * Infrastructure pool (confirmed with the designer), so this
     * requires every single Monolith Shard in the game to be within
     * range at once.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicPoof(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $nearbyKeys = array_map(Board::key(...), Board::cellsWithinDistance($casterCell, 2, $state->board));

        $count = 0;
        foreach ($nearbyKeys as $key) {
            $count += count(array_filter($state->board[$key]['infrastructure'], fn (string $i) => $i === 'monolith_shard'));
        }

        if ($count < 12) {
            return [$state, [], false];
        }

        $state = $state->with(['golem' => $state->golemWith(['cell' => null]), 'golemSkipped' => true, 'prophecy' => $state->prophecy + 3]);

        return [$state, [
            ['type' => 'golem_removed', 'role_key' => null, 'payload' => []],
            ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 3]],
        ], true];
    }

    /**
     * Scapegoat's catharsis: "If you have all 4 Relics with 'Dark ...'
     * names equipped: Allies lose 10 Health. Then, trash this, and all
     * Dark Relics. Gain 📜📜." There are exactly 4 Dark Relics in the
     * data (Dark shackles, Dark limitations, Dark sacrifice, Dark sigil).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicScapegoatsCatharsis(GameState $state, string $actorRole): array
    {
        $darkKeys = ['dark-shackles', 'dark-limitations', 'dark-sacrifice', 'dark-sigil'];
        $equipped = array_values($state->wanderers[$actorRole]['equipped']);

        if (count(array_intersect($darkKeys, $equipped)) !== count($darkKeys)) {
            return [$state, [], false];
        }

        $events = [];
        $casterCell = $state->wanderers[$actorRole]['cell'];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if ($role === $actorRole || Board::key($cell) !== Board::key($casterCell)) {
                continue;
            }

            [$state, $damageEvents] = self::applyDamage($state, $role, 10);
            $events = [...$events, ...$damageEvents];
        }

        foreach ($darkKeys as $darkKey) {
            $state = self::trashEquippedRelic($state, $actorRole, $darkKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $darkKey]];
        }

        $state = $state->with(['prophecy' => $state->prophecy + 2]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 2]];

        return [$state, $events, true];
    }

    /**
     * Silent hunting cloak: "When on a space that produces 🐾, with the
     * Golem, tap its next 2 untapped cards."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicSilentHuntingCloak(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $location = LocationCatalog::find($state->board[Board::key($casterCell)]['location_key']);

        if ($location === null || $location->producesEmoji !== '🐾' || ! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, []];
        }

        [$state, $events] = self::tapGolemDrawPile($state, 2);

        return [$state, $events];
    }

    /**
     * Jericho inscription: "If you are adjacent to a Wall, the space
     * beyond the Wall's space contains the Golem, and all 3 🎶 Relics
     * are equipped by Wanderers adjacent to that Wall: destroy the
     * Wall, gain 📜, and add a 🎨 Glyph to the Golem's mat. Then trash
     * this." "The space beyond" is the next cell continuing in the same
     * direction from the caster, through the Wall.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicJerichoInscription(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $instruments = ['lute', 'pipe', 'tabor-drum'];
        $golemCell = $state->golem['cell'];

        foreach (Board::neighbors($casterCell) as $direction => $wallCell) {
            $wallKey = Board::key($wallCell);

            if (! array_key_exists($wallKey, $state->board)) {
                continue;
            }

            if (! in_array('walls', $state->board[$wallKey]['infrastructure'], true)) {
                continue;
            }

            $beyondKey = Board::key(Board::neighbor($wallCell, $direction));

            if ($golemCell === null || Board::key($golemCell) !== $beyondKey) {
                continue;
            }

            $wallNeighborKeys = array_map(Board::key(...), Board::occupiedNeighbors($wallCell, $state->board));
            $equippedNearWall = [];

            foreach (self::activeWanderers($state) as $role => $cell) {
                if (in_array(Board::key($cell), $wallNeighborKeys, true)) {
                    $equippedNearWall = [...$equippedNearWall, ...array_values($state->wanderers[$role]['equipped'])];
                }
            }

            if (count(array_intersect($instruments, $equippedNearWall)) !== count($instruments)) {
                continue;
            }

            $infrastructure = $state->board[$wallKey]['infrastructure'];
            $index = array_search('walls', $infrastructure, true);
            unset($infrastructure[$index]);
            $board = $state->board;
            $board[$wallKey] = [...$board[$wallKey], 'infrastructure' => array_values($infrastructure)];
            $pool = $state->infrastructurePool;
            $pool['walls']++;
            $state = $state->with(['board' => $board, 'infrastructurePool' => $pool]);

            [$state, $events, $trash] = self::inscribeOutcome($state, '🎨', $actorRole, 1, true);
            $events[] = ['type' => 'infrastructure_returned', 'role_key' => null, 'payload' => ['cell' => $wallCell]];

            return [$state, $events, $trash];
        }

        return [$state, [], false];
    }

    /**
     * Past troubles: "Shuffle Glyphs on the Golem's mat, including
     * face-up and discarded Glyphs. Randomly re-fill the 4 active slots
     * with the shuffled cards, and return the rest to the Glyph discard
     * pile." Only the active Glyphs and the discard pile are shuffled —
     * the main face-down Glyph deck is untouched.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicPastTroubles(GameState $state): array
    {
        $pool = [...$state->golem['glyphs'], ...$state->glyphDiscardPile];
        shuffle($pool);

        $newActive = array_slice($pool, 0, self::GOLEM_MAX_GLYPHS);
        $newDiscard = array_slice($pool, self::GOLEM_MAX_GLYPHS);

        $state = $state->with([
            'golem' => $state->golemWith(['glyphs' => $newActive]),
            'glyphDiscardPile' => $newDiscard,
        ]);

        return [$state, [['type' => 'glyphs_reshuffled', 'role_key' => null, 'payload' => []]], false];
    }

    /**
     * Pray: "If you are exactly 3 spaces away from the Golem, add a 📖
     * Glyph to its mat. Then, gain 📜 and trash this."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicPray(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $golemCell = $state->golem['cell'];

        if ($golemCell === null || Board::distance($casterCell, $golemCell, $state->board) !== 3) {
            return [$state, [], false];
        }

        return self::inscribeOutcome($state, '📖', $actorRole, 1, true);
    }

    /**
     * Re-creation: "Recover 1 trashed Relic, and trash this." Draws
     * randomly from the new global trashedRelics pool (every Relic
     * ever trashed, not dropped — see trashEquippedRelic).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicReCreation(GameState $state, string $actorRole): array
    {
        $pool = $state->trashedRelics;

        if ($pool === []) {
            return [$state, [], false];
        }

        $index = array_rand($pool);
        $recovered = $pool[$index];
        unset($pool[$index]);
        $pool = array_values($pool);

        $wanderer = $state->wanderers[$actorRole];
        $state = $state->with([
            'wanderers' => $state->wanderersWith($actorRole, ['holding' => [...$wanderer['holding'], $recovered]]),
            'trashedRelics' => $pool,
        ]);

        return [$state, [['type' => 'relic_recovered', 'role_key' => $actorRole, 'payload' => ['relic_key' => $recovered]]], true];
    }

    /**
     * Cabalist brew: "Lose 2 Health, and trash this. Then, you or any
     * Ally immediately plays a 🌿 Relic that gets triple strength.
     * Gain 📜." Runs the chosen Relic's own resolveRelicAction exactly
     * like Warlock's robes does, then snapshots the HP change it caused
     * across every Wanderer and applies 2x more of that same delta —
     * tripling the effect without needing to know each heal Relic's
     * hardcoded amount. $move['target_role'] picks the performer (an
     * Ally, or omitted for the caster themselves);
     * $move['other_relic_key'] picks which of the performer's equipped
     * Relics to triple.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicCabalistBrew(GameState $state, string $actorRole, array $move): array
    {
        $hp = max(0, $state->wanderers[$actorRole]['hp'] - 2);
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
        $events = [['type' => 'wanderer_damaged', 'role_key' => $actorRole, 'payload' => ['amount' => 2]]];

        $state = $state->with(['prophecy' => $state->prophecy + 1]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];

        $performerRole = self::resolveAllyTarget($state, $actorRole, $move) ?? $actorRole;
        $otherRelicKey = (string) ($move['other_relic_key'] ?? '');
        $otherBaseKey = (string) preg_replace('/-\d+$/', '', $otherRelicKey);

        if ($otherBaseKey === '' || $otherBaseKey === 'cabalist-brew' || ! in_array($otherRelicKey, $state->wanderers[$performerRole]['equipped'], true)) {
            return [$state, $events, true];
        }

        $hpBefore = array_map(fn (array $w) => $w['hp'], $state->wanderers);

        [$state, $innerEvents, $innerTrash] = self::resolveRelicAction($state, $performerRole, $otherBaseKey, $otherRelicKey, $move);
        $events = [...$events, ...$innerEvents];

        foreach ($state->wanderers as $role => $wanderer) {
            $gained = $wanderer['hp'] - $hpBefore[$role];

            if ($gained > 0) {
                $tripled = min(self::maxHpFor($state, $role), $wanderer['hp'] + 2 * $gained);
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $tripled])]);
                $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $tripled]];
            }
        }

        if ($innerTrash) {
            $state = self::trashEquippedRelic($state, $performerRole, $otherRelicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $performerRole, 'payload' => ['relic_key' => $otherRelicKey]];
        }

        return [$state, $events, true];
    }

    /**
     * Defanging tool: "If you are on the Golem's space, tuck the next
     * card from the Golem's draw pile under this. Then, the Golem
     * plays 3 cards. After doing this 3 times, trash this and
     * gain 📜📜📜. If you remove this card from your mat, all progress
     * is lost" (see dropRelic). Progress is tracked in the caster's
     * new tucked_cards map, keyed by this Relic's own key.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicDefangingTool(GameState $state, string $actorRole): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

        $golem = $state->golem;

        if ($golem['draw_pile'] === []) {
            if ($golem['discard_pile'] === []) {
                return [$state, [], false];
            }

            $golem['draw_pile'] = array_map(
                fn (string $key) => ['key' => $key, 'tapped' => false],
                $golem['discard_pile']
            );
            shuffle($golem['draw_pile']);
            $golem['discard_pile'] = [];
        }

        $tuckedCard = array_shift($golem['draw_pile']);
        $state = $state->with(['golem' => $golem]);

        $tuckedCards = $state->wanderers[$actorRole]['tucked_cards'];
        $tuckedCards['defanging-tool'] = [...($tuckedCards['defanging-tool'] ?? []), $tuckedCard['key']];
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['tucked_cards' => $tuckedCards])]);

        [$state, $playEvents] = self::golemPlayCards($state, 3);
        $events = [
            ['type' => 'card_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => 'defanging-tool', 'card_key' => $tuckedCard['key']]],
            ...$playEvents,
        ];

        $doneThreeTimes = count($tuckedCards['defanging-tool']) >= 3;

        // "After doing this 3 times, trash this and gain 📜📜📜."
        if ($doneThreeTimes) {
            $state = $state->with(['prophecy' => $state->prophecy + 3]);
            $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 3]];
        }

        return [$state, $events, $doneThreeTimes];
    }

    /**
     * Golem log: "If you are in the Golem's space: look at the top 3
     * cards of its draw pile, then tuck one of them here." Same
     * can't-supply-the-choice-in-the-same-move problem as Invigoration/
     * Rift in time/Epiphany — the Wanderer doesn't know which 3 cards
     * are up top until this Use already reveals them, so the pick
     * parks on pendingGolemLog for a free (no extra action cost)
     * resolve_golem_log move instead of taking $move['card_key'] here.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function offerGolemLog(GameState $state, string $actorRole): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

        $topThree = array_slice($state->golem['draw_pile'], 0, 3);
        $state = $state->with(['pendingGolemLog' => $actorRole]);

        $events = [[
            'type' => 'golem_draw_pile_revealed', 'role_key' => $actorRole,
            'payload' => ['card_keys' => array_map(fn (array $c) => $c['key'], $topThree)],
        ]];

        return [$state, $events, false];
    }

    /**
     * "...then tuck one of them here. When there are 3 cards tucked
     * here, move them to the Golem discard pile, trash this, and gain
     * 📜. If you drop this Relic, return the cards to its discard pile
     * and lose your progress" (see dropRelic). $move['card_key'] picks
     * which of the top 3 revealed by offerGolemLog(); not re-checking
     * golemSharesCellWith here — the choice was already earned by the
     * original Use, same as every other pendingXxx resolve move.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveGolemLog(GameState $state, string $actorRole, array $move): array
    {
        if ($state->pendingGolemLog !== $actorRole) {
            throw new InvalidMoveException('There is no Golem log choice pending for you.');
        }

        $state = $state->with(['pendingGolemLog' => null]);

        $drawPile = $state->golem['draw_pile'];
        $topThree = array_slice($drawPile, 0, 3);
        $chosenKey = (string) ($move['card_key'] ?? '');

        $pileIndex = null;
        foreach ($topThree as $i => $cardEntry) {
            if ($cardEntry['key'] === $chosenKey) {
                $pileIndex = $i;
                break;
            }
        }

        if ($pileIndex === null) {
            return [$state, []];
        }

        unset($drawPile[$pileIndex]);
        $drawPile = array_values($drawPile);
        $state = $state->with(['golem' => $state->golemWith(['draw_pile' => $drawPile])]);

        $tuckedCards = $state->wanderers[$actorRole]['tucked_cards'];
        $tuckedCards['golem-log'] = [...($tuckedCards['golem-log'] ?? []), $chosenKey];
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['tucked_cards' => $tuckedCards])]);
        $events = [['type' => 'card_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => 'golem-log', 'card_key' => $chosenKey]]];

        if (count($tuckedCards['golem-log']) < 3) {
            return [$state, $events];
        }

        $golem = $state->golem;
        $golem['discard_pile'] = [...$golem['discard_pile'], ...$tuckedCards['golem-log']];
        $state = $state->with(['golem' => $golem]);

        $state = self::trashEquippedRelic($state, $actorRole, 'golem-log');
        $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => 'golem-log']];

        $state = $state->with(['prophecy' => $state->prophecy + 1]);
        $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => 1]];

        return [$state, $events];
    }

    /**
     * Merchant's gloves: "If you are on a space that produces 🪙, you
     * may freely exchange any number of Relics with Allies, even if
     * they are on other spaces." Unlike every other "Ally" clause in
     * this engine, this one explicitly drops the same-space
     * requirement — $move['exchanges'] is a list of
     * {from_role, to_role, relic_key}, each moving one Relic from one
     * Wanderer (equipped or held) into another's holding, regardless
     * of position. A Bound Relic can't change hands this way either,
     * same as it can't be dropped.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicMerchantsGloves(GameState $state, string $actorRole, array $move): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $location = LocationCatalog::find($state->board[Board::key($casterCell)]['location_key']);

        if ($location === null || $location->producesEmoji !== '🪙') {
            return [$state, []];
        }

        $exchanges = $move['exchanges'] ?? [];

        if (! is_array($exchanges)) {
            return [$state, []];
        }

        $events = [];

        foreach ($exchanges as $exchange) {
            if (! is_array($exchange)) {
                continue;
            }

            $fromRole = $exchange['from_role'] ?? null;
            $toRole = $exchange['to_role'] ?? null;
            $relicKey = $exchange['relic_key'] ?? null;

            if (! is_string($fromRole) || ! is_string($toRole) || ! is_string($relicKey)
                || ! isset($state->wanderers[$fromRole]) || ! isset($state->wanderers[$toRole])) {
                continue;
            }

            $fromWanderer = $state->wanderers[$fromRole];
            $fromSlot = array_search($relicKey, $fromWanderer['equipped'], true);

            if ($fromSlot !== false) {
                if (RelicCatalog::find($relicKey)?->isBound === true) {
                    continue;
                }

                $equipped = $fromWanderer['equipped'];
                $equipped[$fromSlot] = null;
                $state = $state->with(['wanderers' => $state->wanderersWith($fromRole, ['equipped' => $equipped])]);
            } elseif (in_array($relicKey, $fromWanderer['holding'], true)) {
                $state = $state->with(['wanderers' => $state->wanderersWith($fromRole, [
                    'holding' => array_values(array_diff($fromWanderer['holding'], [$relicKey])),
                ])]);
            } else {
                continue;
            }

            $toWanderer = $state->wanderers[$toRole];
            $state = $state->with(['wanderers' => $state->wanderersWith($toRole, [
                'holding' => [...$toWanderer['holding'], $relicKey],
            ])]);

            $events[] = ['type' => 'relic_exchanged', 'role_key' => null, 'payload' => ['relic_key' => $relicKey, 'from' => $fromRole, 'to' => $toRole]];
        }

        return [$state, $events];
    }

    /**
     * Dispatches each "Inscribe" card on its full disambiguated key —
     * see resolveRelicAction's docblock for why. All 12 add a specific
     * Glyph type under a specific condition; all but inscribe-12 then
     * trash themselves (per each one's own printed text).
     *
     * inscribe-7 ("When the Golem hits you, add a 🌑 Glyph...") is a
     * reactive trigger, not a player-initiated action — it's a no-op
     * here specifically (nothing to do if someone tries to "use" it
     * manually); its real behavior fires from applyGolemAttackReactions
     * whenever the Golem actually damages its holder.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function resolveInscribe(GameState $state, string $actorRole, string $relicKey): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $onGolemSpace = self::golemSharesCellWith($state, $actorRole);
        $noOp = [$state, [], false];

        return match ($relicKey) {
            'inscribe', 'inscribe-2', 'inscribe-3' => $onGolemSpace
                ? self::inscribeOutcome($state, '⏳', $actorRole, 1, true)
                : $noOp,

            'inscribe-4' => self::inscribePrisonJudgment($state, $actorRole),

            'inscribe-5' => $onGolemSpace && $state->wanderers[$actorRole]['shame'] === 0
                ? self::inscribeOutcome($state, '⚖️', $actorRole, 1, true)
                : $noOp,

            'inscribe-6' => $onGolemSpace && in_array('monolith_shard', $state->board[Board::key($casterCell)]['infrastructure'], true)
                ? self::inscribeOutcome($state, '✨', $actorRole, 1, true)
                : $noOp,

            'inscribe-7' => $noOp,

            'inscribe-8' => $onGolemSpace
                ? self::inscribeOutcome($state, '🎨', $actorRole, 1, true)
                : $noOp,

            'inscribe-9' => $onGolemSpace
                ? self::inscribeOutcome($state, '🐾', $actorRole, 1, true)
                : $noOp,

            'inscribe-10' => $onGolemSpace
                ? self::inscribeOutcome($state, '📖', $actorRole, 1, true)
                : $noOp,

            'inscribe-11' => self::allWanderersShareGolemSpace($state)
                ? self::inscribeOutcome($state, '🤝', $actorRole, 1, true)
                : $noOp,

            'inscribe-12' => $onGolemSpace && self::allWanderersMutuallyAtLeastDistance($state, 2)
                ? self::inscribeOutcome($state, '🤝', $actorRole, 1, false)
                : $noOp,

            default => $noOp,
        };
    }

    /**
     * Shared tail for every "Inscribe"-family Relic: add a Glyph of
     * $type, grant $prophecy only if that actually happened (the
     * deck might have no Glyph of that type left), and only trash the
     * Relic ($trashIfSuccess) if it did too — a condition being
     * satisfied doesn't mean a matching Glyph still exists to draw.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function inscribeOutcome(GameState $state, string $type, string $actorRole, int $prophecy, bool $trashIfSuccess): array
    {
        [$state, $events] = self::addGlyphOfType($state, $type, $actorRole, $prophecy);

        return [$state, $events, $trashIfSuccess && $events !== []];
    }

    /**
     * Inscribe #4: "If you and the Golem are in the Prison, you have 0
     * Shame tokens, and the Golem has 3+ Shame tokens: add a ⚖️ Glyph to
     * the Golem's mat ... and trash this."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function inscribePrisonJudgment(GameState $state, string $actorRole): array
    {
        if (! self::inscribePrisonJudgmentReady($state, $actorRole)) {
            return [$state, [], false];
        }

        return self::inscribeOutcome($state, '⚖️', $actorRole, 3, true);
    }

    private static function inscribePrisonJudgmentReady(GameState $state, string $actorRole): bool
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $onPrison = $state->board[Board::key($casterCell)]['location_key'] === 'prison';
        $golemThere = self::golemSharesCellWith($state, $actorRole);
        $noShame = $state->wanderers[$actorRole]['shame'] === 0;
        $golemHighShame = self::effectiveGolemShame($state) >= 3;

        return $onPrison && $golemThere && $noShame && $golemHighShame;
    }

    private static function allWanderersShareGolemSpace(GameState $state): bool
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return false;
        }

        foreach (self::activeWanderers($state) as $cell) {
            if (Board::key($cell) !== Board::key($golemCell)) {
                return false;
            }
        }

        return true;
    }

    private static function allWanderersMutuallyAtLeastDistance(GameState $state, int $min): bool
    {
        $cells = array_values(self::activeWanderers($state));

        foreach ($cells as $i => $a) {
            foreach ($cells as $j => $b) {
                if ($i === $j) {
                    continue;
                }

                $distance = Board::distance($a, $b, $state->board);

                if ($distance === null || $distance < $min) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Golem Whistle: moves the Golem onto the caster's own space, subject
     * to the same Walls restriction as its normal movement.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicSummonGolem(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];

        if (in_array(Board::key($casterCell), $state->wallKeys(), true)) {
            return [$state, []];
        }

        $golemCellBefore = $state->golem['cell'];
        $state = $state->with(['golem' => $state->golemWith(['cell' => $casterCell])]);
        $events = [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $casterCell]]];

        if ($golemCellBefore !== null) {
            [$state, $e] = self::moveDarkShacklesWanderers($state, $golemCellBefore, $casterCell);
            $events = [...$events, ...$e];
        }

        return [$state, $events];
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicSwapWithAlly(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = $move['target_role'] ?? null;

        if (! is_string($targetRole) || $targetRole === $actorRole || ! isset($state->wanderers[$targetRole])) {
            return [$state, []];
        }

        $actorCell = $state->wanderers[$actorRole]['cell'];
        $targetCell = $state->wanderers[$targetRole]['cell'];

        if ($actorCell === null || $targetCell === null) {
            return [$state, []];
        }

        if (self::entryBlocked($state, $actorRole, $targetCell) || self::entryBlocked($state, $targetRole, $actorCell)) {
            return [$state, []];
        }

        $wanderers = $state->wanderersWith($actorRole, ['cell' => $targetCell]);
        $state = $state->with(['wanderers' => $wanderers]);
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['cell' => $actorCell])]);

        $events = [['type' => 'wanderers_swapped', 'role_key' => $actorRole, 'payload' => ['with' => $targetRole]]];

        [$state, $e] = self::moveDarkShacklesGolem($state, $actorRole, $actorCell, $targetCell);
        $events = [...$events, ...$e];

        [$state, $e] = self::moveDarkShacklesGolem($state, $targetRole, $targetCell, $actorCell);
        $events = [...$events, ...$e];

        return [$state, $events];
    }

    /**
     * Removes a Relic from an equipped slot permanently — unlike drop, it
     * doesn't go back onto the board.
     */
    private static function trashEquippedRelic(GameState $state, string $actorRole, string $relicKey): GameState
    {
        $equipped = $state->wanderers[$actorRole]['equipped'];
        $slot = array_search($relicKey, $equipped, true);

        if ($slot === false) {
            return $state;
        }

        $equipped[$slot] = null;

        return $state->with([
            'wanderers' => $state->wanderersWith($actorRole, ['equipped' => $equipped]),
            'trashedRelics' => [...$state->trashedRelics, $relicKey],
        ]);
    }

    /**
     * Dark limitations: "Your Health can never exceed 7" (raised from
     * 5 in the 2026-11-24 rules.md revision). Cabalist's Gown keeps
     * its own, separate cap of 5. Every heal site caps at whichever of
     * these applies instead of the usual 15.
     */
    private static function maxHpFor(GameState $state, string $role): int
    {
        $equipped = $state->wanderers[$role]['equipped'];

        // Cabalist's Gown: "your Health ... cannot rise above 5."
        if (in_array('cabalists-gown', $equipped, true)) {
            return 5;
        }

        if (in_array('dark-limitations', $equipped, true)) {
            return 7;
        }

        return 15;
    }

    /**
     * The Monolith within: "Your space is considered to have +1 Monolith
     * Shard on it, even if there is already one there." Only matters for
     * presence/absence checks (there's no mechanic that counts shards per
     * space), so this is just an OR against the real board infrastructure
     * for whichever Wanderer's "their own space" is being checked.
     *
     * @param  array{0: int, 1: int}  $cell
     */
    private static function hasMonolithShardFor(GameState $state, string $role, array $cell): bool
    {
        if (in_array('monolith_shard', $state->board[Board::key($cell)]['infrastructure'], true)) {
            return true;
        }

        return in_array('the-monolith-within', $state->wanderers[$role]['equipped'], true);
    }

    /**
     * Covers Emperor-phase steps 4 (discard) and 5 (draw back to 5) in one
     * action, then hands off to the Wanderer phase. Relic dealing (step 2)
     * happens automatically in playLocation() rather than as a separate
     * step here; Infrastructure (step 3) is its own place_infrastructure
     * move, not gated by ending the phase.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function endEmperorPhase(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, RoleKeys::EMPEROR, 'emperor');

        $discard = array_values(array_intersect($state->locationHand, $move['discard'] ?? []));
        $hand = array_values(array_diff($state->locationHand, $discard));
        $discardPile = [...$state->locationDiscard, ...$discard];
        $deck = $state->locationDeck;

        $needed = self::EMPEROR_HAND_SIZE - count($hand);
        if ($needed > 0 && count($deck) < $needed && $discardPile !== []) {
            shuffle($discardPile);
            $deck = [...$deck, ...$discardPile];
            $discardPile = [];
        }

        $drawn = array_splice($deck, 0, max(0, $needed));
        $hand = [...$hand, ...$drawn];

        // rules.md (Emperor phase, step 5): "If you ever have a hand of
        // 5 cards that you are not able to play, you may discard
        // additional cards and draw replacements for them. Continue
        // doing this until you have at least 1 card you are allowed to
        // play." Modeled as automatic — there's no real downside to
        // redrawing an entirely unplayable hand, so there's no decision
        // to surface to the Emperor. Stops once either a playable card
        // turns up or the deck and discard pile are both drained (every
        // remaining card has been seen and none fit the current board).
        $mulligans = 0;
        while (
            ! self::anyLocationPlayable($state, $hand) &&
            ($deck !== [] || $discardPile !== [])
        ) {
            $discardPile = [...$discardPile, ...$hand];
            $hand = [];

            if (count($deck) < self::EMPEROR_HAND_SIZE && $discardPile !== []) {
                shuffle($discardPile);
                $deck = [...$deck, ...$discardPile];
                $discardPile = [];
            }

            $hand = array_splice($deck, 0, min(self::EMPEROR_HAND_SIZE, count($deck)));
            $mulligans++;
        }

        $wandererOrder = RoleKeys::wanderers($state->wandererCount);
        $firstRole = $wandererOrder[0];
        $actionsPerTurn = self::turnStartActions($state, $firstRole);

        $wanderers = $state->wanderers;
        $wanderers[$firstRole] = [...$wanderers[$firstRole], 'actions_remaining' => $actionsPerTurn, 'move_blocked_this_turn' => false, 'has_moved_this_turn' => false, 'pending_bonus_actions' => 0];

        $state = $state->with([
            'locationHand' => $hand,
            'locationDiscard' => $discardPile,
            'locationDeck' => $deck,
            'wanderers' => $wanderers,
            'phase' => 'wanderer',
            'wandererOrder' => $wandererOrder,
            'activeWandererIndex' => 0,
        ]);

        $events = [
            ['type' => 'emperor_phase_ended', 'role_key' => $actorRole, 'payload' => ['discarded' => $discard]],
        ];

        if ($mulligans > 0) {
            $events[] = ['type' => 'emperor_hand_mulliganed', 'role_key' => $actorRole, 'payload' => ['count' => $mulligans]];
        }

        $events[] = ['type' => 'wanderer_turn_started', 'role_key' => $firstRole, 'payload' => ['actions' => $actionsPerTurn]];

        if (self::wandererStartsOnFruitTree($state, $firstRole)) {
            $events[] = ['type' => 'fruit_tree_bonus_action', 'role_key' => $firstRole, 'payload' => []];
        }

        return [$state, $events];
    }

    private static function wandererStartsOnFruitTree(GameState $state, string $role): bool
    {
        $cell = $state->wanderers[$role]['cell'];

        return $cell !== null && in_array('fruit_tree', $state->board[Board::key($cell)]['infrastructure'], true);
    }

    /**
     * @param  list<string>  $handKeys
     */
    private static function anyLocationPlayable(GameState $state, array $handKeys): bool
    {
        foreach ($handKeys as $key) {
            $location = LocationCatalog::find($key);

            if ($location === null) {
                continue;
            }

            foreach (Board::placeableCells($state->board) as $cell) {
                $playable = true;

                foreach ($location->costs as $cost) {
                    if (! self::effectiveProduces($state, $cell, $cost)) {
                        $playable = false;
                        break;
                    }
                }

                if ($playable) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Catacombs: "A Wanderer can only enter this space if they were
     * exhausted earlier in the game." Scriptorium (rules.md 2026-11-24
     * revision — previously a one-time "move all Shards to the pool
     * when built" effect, now): "Ongoing: Wanderers may only enter this
     * Location if it has 4 adjacent locations that have Monolith
     * Shards." Both are entry restrictions on the destination, so they
     * share this one chokepoint. Applies to every way a Wanderer's cell
     * can change — not just a plain move_wanderer — so every caller
     * below checks this before actually moving someone into $cell,
     * regardless of whether the move was their own choice (an explicit
     * move throws) or a side effect of something else (Guide rope, a
     * relic, the Golem forcing them in) that should just skip that
     * particular Wanderer rather than fail the whole action.
     *
     * @param  array{0: int, 1: int}  $cell
     */
    private static function entryBlocked(GameState $state, string $role, array $cell): bool
    {
        $locationKey = $state->board[Board::key($cell)]['location_key'];

        if ($locationKey === 'catacombs') {
            return ! $state->wanderers[$role]['was_ever_exhausted'];
        }

        if ($locationKey === 'scriptorium') {
            $shardNeighbors = count(array_filter(
                Board::occupiedNeighbors($cell, $state->board),
                fn (array $neighbor) => in_array('monolith_shard', $state->board[Board::key($neighbor)]['infrastructure'], true),
            ));

            return $shardNeighbors < 4;
        }

        return false;
    }

    /**
     * Road: "It always costs 1 action to move. But when a Wanderer
     * moves into a space containing a Road, they may continue to an
     * adjacent space for free. If multiple contiguous spaces contain
     * Roads, the Wanderer may be able to traverse many spaces for just
     * 1 action." Changed from the original wording (any move off a
     * Road space was free, including a Wanderer's very first move of
     * the turn if they simply started it standing on one) — "allowing
     * totally free traversal with roads just trivialized movement,
     * especially late game" (designer's own justification, rulebook
     * revision #2). Modeled via has_moved_this_turn: the ORIGIN having
     * a Road only makes a move free once this Wanderer has already
     * paid for at least one move this turn; chains naturally through
     * consecutive Road spaces after that, same as before.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function moveWanderer(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];
        $originInfrastructure = $state->board[Board::key($wanderer['cell'])]['infrastructure'];

        $direction = (string) ($move['direction'] ?? '');
        $to = Board::neighbor($wanderer['cell'], $direction);
        $toKey = Board::key($to);

        if (! array_key_exists($toKey, $state->board)) {
            throw new InvalidMoveException('Wanderers must always be on a Location.');
        }

        $destinationInfrastructure = $state->board[$toKey]['infrastructure'];

        // Road: "It always costs 1 action to move. But when a Wanderer
        // moves into a space containing a Road, they may continue to an
        // adjacent space that also contains a road for free. If
        // multiple contiguous spaces contain Roads, the Wanderer may be
        // able to traverse many spaces for just 1 action." The FIRST
        // move of a turn always costs its action, even if the Wanderer
        // is already standing on a Road when their turn starts —
        // has_moved_this_turn gates that. Only moves after that first
        // paid one can be free, and only when BOTH the space being left
        // and the space being entered have a Road — rules.md 2026-11-?:
        // tightened from "any adjacent space" to require the
        // destination to also have a Road. Earthquake Glyph: "Roads and
        // walls have no effect" — Roads simply stop granting this.
        $freeMove = $wanderer['has_moved_this_turn']
            && in_array('road', $originInfrastructure, true)
            && in_array('road', $destinationInfrastructure, true)
            && ! in_array('earthquake', $state->golem['glyphs'], true);

        if (! $freeMove && $wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        self::requireNotExhausted($wanderer);

        if ($wanderer['move_blocked_this_turn']) {
            throw new InvalidMoveException('Incarceration: you cannot move for the rest of this turn.');
        }

        if (self::entryBlocked($state, $actorRole, $to)) {
            throw new InvalidMoveException('Catacombs: only a Wanderer who has been exhausted before may enter.');
        }

        // Exile: "Wanderers may only enter this space if they have 3
        // Shame tokens."
        if ($state->board[$toKey]['location_key'] === 'exile' && $wanderer['shame'] < 3) {
            throw new InvalidMoveException('Exile: only a Wanderer with 3 Shame tokens may enter.');
        }

        // Barricading Glyph: "Wanderers cannot move into the Golem's
        // space (but the Golem can still move into theirs)."
        if ($state->golem['cell'] !== null && Board::key($state->golem['cell']) === $toKey
            && in_array('barricading', $state->golem['glyphs'], true)) {
            throw new InvalidMoveException('Barricading: Wanderers cannot move into the Golem\'s space.');
        }

        $actionsRemaining = $wanderer['actions_remaining'] - ($freeMove ? 0 : 1);

        // Emperor's Knowledge: "When moving, move an additional space for
        // every 2 Relics you have equipped that start with 'Emperor's
        // __.'" Modeled as refunding the action this move cost, the same
        // way Road already grants a move for free, rather than literally
        // chaining a second step (there's no second direction to move in
        // from a single move_wanderer call). Requires Emperor's Knowledge
        // itself to be equipped — merely having 2+ OTHER "Emperor's __"
        // Relics equipped isn't enough (confirmed in the engine audit:
        // this previously granted the bonus to anyone holding e.g.
        // Emperor's Diary + Emperor's Robes, with Knowledge itself not
        // even equipped).
        if (in_array('emperors-knowledge', $wanderer['equipped'], true)) {
            $actionsRemaining += intdiv(self::countEmperorsRelics(array_values($wanderer['equipped'])), 2);
        }

        // Buoyant: "Wanderers get +2🦶 for all of their movements" —
        // modeled the same way as every other movement bonus here
        // (Road/Fruit tree/Emperor's Knowledge): refund extra actions
        // on the move itself rather than literally chaining steps.
        if (in_array('buoyant', $state->golem['glyphs'], true)) {
            $actionsRemaining += 2;
        }

        // Revelatory Glyph: "When moving, Wanderers may choose to lose
        // any amount of Health. +1🦶 for each Health you lost."
        // Deliberately not routed through applyDamage() — Dark
        // Relics/Cabalist's Gown reducing a *voluntary* sacrifice
        // would break the 1:1 Health-lost-for-actions-gained exchange
        // the player is explicitly choosing, so this clamps directly
        // against current HP instead.
        $sacrificedHp = 0;
        if (in_array('revelatory', $state->golem['glyphs'], true)) {
            $requested = (int) ($move['sacrifice_hp'] ?? 0);
            $sacrificedHp = max(0, min($requested, $wanderer['hp']));
            $actionsRemaining += $sacrificedHp;
        }

        $newHp = $wanderer['hp'] - $sacrificedHp;

        $wanderers = $state->wanderersWith($actorRole, [
            'cell' => $to,
            'actions_remaining' => $actionsRemaining,
            'hp' => $newHp,
            'has_moved_this_turn' => true,
        ]);

        $state = $state->with(['wanderers' => $wanderers]);

        if ($newHp === 0 && $wanderer['hp'] > 0) {
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['was_ever_exhausted' => true])]);
        }

        $events = [['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $to, 'free' => $freeMove]]];

        if ($sacrificedHp > 0) {
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $actorRole, 'payload' => ['amount' => $sacrificedHp]];
        }

        if (self::hasMonolithShardFor($state, $actorRole, $to) && in_array('rejuvenation-gown', $wanderer['equipped'], true)) {
            $hp = min(self::maxHpFor($state, $actorRole), $state->wanderers[$actorRole]['hp'] + 2);
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]];
        }

        [$state, $tiesEvents] = self::applyTiesThatBindOnMove($state, $actorRole, $to);
        $events = [...$events, ...$tiesEvents];

        [$state, $e] = self::moveDarkShacklesGolem($state, $actorRole, $wanderer['cell'], $to);
        $events = [...$events, ...$e];

        // Beds of poison: "If a Wanderer enters this space for any
        // reason (even if they are just passing through while moving),
        // they lose 5 Health." Every step of movement is its own
        // moveWanderer call, so "passing through" is already covered.
        if ($state->board[$toKey]['location_key'] === 'beds-of-poison') {
            [$state, $damageEvents] = self::applyDamage($state, $actorRole, 5);
            $events = [...$events, ...$damageEvents];
        }

        // Guide rope: "When moving from a space with other Wanderers, you
        // may bring them with you." Always taken (there's no downside to
        // an Ally), moving every other active Wanderer who shared the
        // source space for free along with the caster, and applying the
        // same Monolith Shard/Beds of poison destination effects.
        if (in_array('guide-rope', $wanderer['equipped'], true) && $wanderer['cell'] !== null) {
            $sourceKey = Board::key($wanderer['cell']);

            foreach (self::activeWanderers($state) as $otherRole => $otherCell) {
                if ($otherRole === $actorRole || Board::key($otherCell) !== $sourceKey) {
                    continue;
                }

                if (self::entryBlocked($state, $otherRole, $to)) {
                    continue;
                }

                $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['cell' => $to])]);
                $events[] = ['type' => 'wanderer_moved', 'role_key' => $otherRole, 'payload' => ['to' => $to, 'free' => true]];

                [$state, $e] = self::moveDarkShacklesGolem($state, $otherRole, $otherCell, $to);
                $events = [...$events, ...$e];

                if (self::hasMonolithShardFor($state, $otherRole, $to) && in_array('rejuvenation-gown', $state->wanderers[$otherRole]['equipped'], true)) {
                    $hp = min(self::maxHpFor($state, $otherRole), $state->wanderers[$otherRole]['hp'] + 2);
                    $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['hp' => $hp])]);
                    $events[] = ['type' => 'wanderer_healed', 'role_key' => $otherRole, 'payload' => ['hp' => $hp]];
                }

                [$state, $tiesEvents] = self::applyTiesThatBindOnMove($state, $otherRole, $to);
                $events = [...$events, ...$tiesEvents];

                if ($state->board[$toKey]['location_key'] === 'beds-of-poison') {
                    [$state, $damageEvents] = self::applyDamage($state, $otherRole, 5);
                    $events = [...$events, ...$damageEvents];
                }
            }
        }

        // Rally: "After moving, each Ally may 🦶1." Always taken — a free
        // heal with no downside.
        if (in_array('rally', $wanderer['equipped'], true)) {
            foreach (self::activeWanderers($state) as $otherRole => $otherCell) {
                if ($otherRole === $actorRole) {
                    continue;
                }

                $hp = min(self::maxHpFor($state, $otherRole), $state->wanderers[$otherRole]['hp'] + 1);
                $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['hp' => $hp])]);
                $events[] = ['type' => 'wanderer_healed', 'role_key' => $otherRole, 'payload' => ['hp' => $hp]];
            }
        }

        // Reactive Glyph: "Every time a Wanderer enters the Golem's
        // space, it plays a card." Checked against the mover's own
        // destination only (not every Ally Guide rope brings along) —
        // one extra card per move_wanderer call, not per Wanderer who
        // ends up there.
        if ($state->golem['cell'] !== null && Board::key($state->golem['cell']) === $toKey
            && in_array('reactive', $state->golem['glyphs'], true)) {
            [$state, $reactiveEvents] = self::golemPlayCards($state, 1);
            $events = [...$events, ...$reactiveEvents];
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function endWandererTurn(GameState $state, string $actorRole): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        if ($state->pendingInvigoration === $actorRole) {
            throw new InvalidMoveException('You must decide on Invigoration before ending your turn.');
        }

        if ($state->pendingRiftInTime === $actorRole) {
            throw new InvalidMoveException('You must decide on Rift in time before ending your turn.');
        }

        if ($state->pendingEpiphany === $actorRole) {
            throw new InvalidMoveException('You must decide on Epiphany before ending your turn.');
        }

        if ($state->pendingGolemLog === $actorRole) {
            throw new InvalidMoveException('You must decide on Golem log before ending your turn.');
        }

        $events = [];

        // Medicinal herb garden: "Ongoing: If Wanderers end their turn
        // here, 🌿5 at the end of their turn."
        $actorCell = $state->wanderers[$actorRole]['cell'];
        if ($actorCell !== null && $state->board[Board::key($actorCell)]['location_key'] === 'medicinal-herb-garden') {
            $hp = min(self::maxHpFor($state, $actorRole), $state->wanderers[$actorRole]['hp'] + 5);
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]];
        }

        $nextIndex = $state->activeWandererIndex + 1;

        if ($nextIndex < count($state->wandererOrder)) {
            $nextRole = $state->wandererOrder[$nextIndex];
            $actionsPerTurn = self::turnStartActions($state, $nextRole);

            $wanderers = $state->wanderers;
            $wanderers[$nextRole] = [...$wanderers[$nextRole], 'actions_remaining' => $actionsPerTurn, 'move_blocked_this_turn' => false, 'has_moved_this_turn' => false, 'pending_bonus_actions' => 0];

            $state = $state->with(['wanderers' => $wanderers, 'activeWandererIndex' => $nextIndex]);

            $events[] = ['type' => 'wanderer_turn_started', 'role_key' => $nextRole, 'payload' => ['actions' => $actionsPerTurn]];

            if (self::wandererStartsOnFruitTree($state, $nextRole)) {
                $events[] = ['type' => 'fruit_tree_bonus_action', 'role_key' => $nextRole, 'payload' => []];
            }

            return [$state, $events];
        }

        [$state, $houseOfCryptidsEvents] = self::tuckAtHouseOfCryptids($state);
        $events = [...$events, ...$houseOfCryptidsEvents];

        [$state, $endRoundEvents] = self::endRound($state);

        return [$state, [...$events, ...$endRoundEvents]];
    }

    /**
     * House of Cryptids: "Ongoing: If all Wanderers are on the Golem's
     * space when the Wanderer phase ends, tuck a Golem card here. When
     * 4 cards are tucked here, return them to the Golem discard pile
     * and gain 📜📜." Checked right as the Wanderer phase actually
     * ends (the last Wanderer's endWandererTurn, before the Golem
     * phase resolves). Tucks from the top of the draw pile.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function tuckAtHouseOfCryptids(GameState $state): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $houseKey = null;
        foreach ($state->board as $key => $entry) {
            if ($entry['location_key'] === 'house-of-cryptids') {
                $houseKey = $key;
                break;
            }
        }

        if ($houseKey === null) {
            return [$state, []];
        }

        $golemCellKey = Board::key($golemCell);
        foreach (self::activeWanderers($state) as $cell) {
            if (Board::key($cell) !== $golemCellKey) {
                return [$state, []];
            }
        }

        $drawPile = $state->golem['draw_pile'];

        if ($drawPile === []) {
            return [$state, []];
        }

        $cardEntry = array_shift($drawPile);
        $state = $state->with(['golem' => $state->golemWith(['draw_pile' => $drawPile])]);

        $board = $state->board;
        $tucked = [...$board[$houseKey]['tucked'], $cardEntry['key']];
        $events = [['type' => 'golem_card_tucked', 'role_key' => null, 'payload' => ['card_key' => $cardEntry['key']]]];

        if (count($tucked) >= 4) {
            $golem = $state->golem;
            $golem['discard_pile'] = [...$golem['discard_pile'], ...$tucked];
            $state = $state->with(['golem' => $golem]);
            $tucked = [];
            $events[] = ['type' => 'house_of_cryptids_cards_returned', 'role_key' => null, 'payload' => []];
        }

        $board[$houseKey] = [...$board[$houseKey], 'tucked' => $tucked];
        $state = $state->with(['board' => $board]);

        return [$state, $events];
    }

    /**
     * Union Garb: "If you begin your turn on a space with another
     * Wanderer, +1 Action." Fruit tree: "When Wanderers start their
     * turn on this space, they gain 1 action that they must use on
     * this turn" (Farmer's insight doubles it). Checked wherever a
     * Wanderer's actions_remaining is set back to the full per-turn
     * amount.
     */
    private static function turnStartActions(GameState $state, string $role): int
    {
        $actionsPerTurn = $state->actionsPerWandererTurn();
        $cell = $state->wanderers[$role]['cell'];

        if ($cell === null) {
            return $actionsPerTurn;
        }

        $equipped = $state->wanderers[$role]['equipped'];

        if (in_array('union-garb', $equipped, true)) {
            foreach (self::activeWanderers($state) as $otherRole => $otherCell) {
                if ($otherRole !== $role && Board::key($otherCell) === Board::key($cell)) {
                    $actionsPerTurn++;
                    break;
                }
            }
        }

        if (in_array('fruit_tree', $state->board[Board::key($cell)]['infrastructure'], true)) {
            $actionsPerTurn += in_array('farmers-insight', $equipped, true) ? 2 : 1;
        }

        // Energizing Glyph: "Wanderers get +1 Action if they start
        // their turn on the Golem's space."
        if (in_array('energizing', $state->golem['glyphs'], true)
            && $state->golem['cell'] !== null && Board::key($state->golem['cell']) === Board::key($cell)) {
            $actionsPerTurn++;
        }

        // Paradoxical Glyph: whatever got banked in pending_bonus_actions
        // since this Wanderer's last turn started (see resolveGolemPhase())
        // is spent now — the caller is responsible for resetting it to 0
        // alongside setting actions_remaining to this return value.
        $actionsPerTurn += $state->wanderers[$role]['pending_bonus_actions'];

        return $actionsPerTurn;
    }

    /**
     * The ties that bind (rules.md 2026-11-24 revision — Passive ->
     * Action, "start your turn" -> movement-triggered): "Move as
     * usual. If you end your movement in a space with another
     * Wanderer, 🌿3." Unlike the old version, only the mover heals —
     * the Ally doesn't. Checked at every place a Wanderer's `cell`
     * changes via their own movement, same breadth as Rejuvenation
     * gown's Monolith Shard check (moveWanderer, Guide rope's
     * carried-along Allies, telekinesisMove, Magnetic mail/Laborer's
     * Jacket).
     *
     * @param  array{0: int, 1: int}  $to
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyTiesThatBindOnMove(GameState $state, string $role, array $to): array
    {
        if (! in_array('the-ties-that-bind', $state->wanderers[$role]['equipped'], true)) {
            return [$state, []];
        }

        $toKey = Board::key($to);
        $hasAlly = false;

        foreach (self::activeWanderers($state) as $otherRole => $otherCell) {
            if ($otherRole !== $role && Board::key($otherCell) === $toKey) {
                $hasAlly = true;
                break;
            }
        }

        if (! $hasAlly) {
            return [$state, []];
        }

        $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 3);
        $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);

        return [$state, [['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]]]];
    }

    /**
     * Resolves the Golem phase (right to left, refilling the revealed row
     * after each card — see Golem Mat), then advances the round or
     * finishes the game after round 8.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function endRound(GameState $state): array
    {
        [$state, $events] = $state->golemSkipped
            ? [$state, []]
            : self::resolveGolemPhase($state);

        // Fire storm/Roar/Sacrifice of Possessions forced a choice —
        // the round genuinely isn't over yet. resolveGolemCardChoice()
        // picks the rest of this up (remaining cards, then everything
        // below) once every awaited Wanderer has responded.
        if ($state->pendingGolemCardChoice !== null) {
            return [$state, $events];
        }

        // Checked here too (not just apply()'s own post-processing
        // pass) so a Golem move during this very phase that triggers
        // Fragile Chapel's destruction is already reflected before
        // finishRoundAfterGolemPhase() runs its "is it still here?"
        // end-of-game scoring check on round 8 — otherwise the order
        // would be backwards (scoring seeing it as still standing one
        // tick before it's destroyed).
        [$state, $chapelEvents] = self::checkFragileChapelDestruction($state);
        $events = [...$events, ...$chapelEvents];

        return self::finishRoundAfterGolemPhase($state, $events);
    }

    /**
     * Everything endRound() does once the Golem phase (including any
     * forced mid-phase choice) is fully resolved — factored out so
     * resolveGolemCardChoice() can run the exact same tail once it
     * finishes resuming, instead of duplicating it.
     *
     * @param  list<array{type: string, role_key: ?string, payload: array<string, mixed>}>  $events
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function finishRoundAfterGolemPhase(GameState $state, array $events): array
    {
        if ($state->round >= self::ROUND_LIMIT) {
            [$state, $scoringEvents] = self::applyEndOfGameScoring($state);
            $state = $state->with(['phase' => 'finished', 'activeWandererIndex' => null]);
            $events = [...$events, ...$scoringEvents, ['type' => 'game_finished', 'role_key' => null, 'payload' => []]];

            return [$state, $events];
        }

        // Exile: "If all Wanderers are on this location at the end of
        // any round prior to the final round, the game ends
        // immediately." (The round-8 case is already covered above —
        // the game was ending this round regardless.)
        $exileKey = null;
        foreach ($state->board as $key => $entry) {
            if ($entry['location_key'] === 'exile') {
                $exileKey = $key;
                break;
            }
        }

        if ($exileKey !== null) {
            $everyoneInExile = true;
            foreach (self::activeWanderers($state) as $cell) {
                if (Board::key($cell) !== $exileKey) {
                    $everyoneInExile = false;
                    break;
                }
            }

            if ($everyoneInExile) {
                [$state, $scoringEvents] = self::applyEndOfGameScoring($state);
                $state = $state->with(['phase' => 'finished', 'activeWandererIndex' => null]);
                $events = [...$events, ...$scoringEvents, ['type' => 'game_finished', 'role_key' => null, 'payload' => ['reason' => 'exile']]];

                return [$state, $events];
            }
        }

        $nextRound = $state->round + 1;

        // Golem insight/Laborer's Jacket: "Once per round" — cleared as
        // the new round begins.
        $wanderers = $state->wanderers;
        foreach ($wanderers as $role => $wanderer) {
            $wanderers[$role] = [
                ...$wanderer,
                'golem_insight_used_this_round' => false,
                'laborers_jacket_used_this_round' => false,
            ];
        }

        $state = $state->with([
            'round' => $nextRound,
            'phase' => 'emperor',
            'activeWandererIndex' => null,
            'infrastructurePlacedThisTurn' => 0,
            'infrastructurePlacedThisTurnLog' => [],
            'wanderers' => $wanderers,
            'parallelUsedThisRound' => false,
        ]);
        $events[] = ['type' => 'round_started', 'role_key' => null, 'payload' => ['round' => $nextRound]];

        return [$state, $events];
    }

    /**
     * Resolves one Wanderer's response to a pendingGolemCardChoice
     * (Fire storm/Sacrifice of Possessions: which Relic to trash;
     * Roar: which direction to flee). An invalid choice (a Relic not
     * actually equipped, a direction leading nowhere/blocked) is a
     * no-op for that Wanderer specifically — same "no-op rather than
     * reject" handling as every other pendingXxx resolve move — but
     * still counts as decided, so a bad choice can't deadlock the
     * round for everyone else.
     *
     * Once every awaited Wanderer has responded, resumes the Golem
     * phase (whatever cards were still owed) and then runs the exact
     * same round-end tail endRound() would have — this move might
     * finish the round, possibly even the game.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveGolemCardChoice(GameState $state, string $actorRole, array $move): array
    {
        $pending = $state->pendingGolemCardChoice;

        if ($pending === null || ! in_array($actorRole, $pending['awaiting'], true)) {
            throw new InvalidMoveException('There is no Golem card choice pending for you.');
        }

        $events = [];

        if ($pending['type'] === 'trash_relic') {
            $relicKey = (string) ($move['relic_key'] ?? '');

            if ($relicKey === '' || self::isStatusToken($relicKey) || ! in_array($relicKey, $state->wanderers[$actorRole]['equipped'], true)) {
                throw new InvalidMoveException('That is not one of your equipped Relics.');
            }

            $state = self::trashEquippedRelic($state, $actorRole, $relicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]];
        } elseif ($pending['type'] === 'move_direction') {
            $direction = (string) ($move['direction'] ?? '');
            $cell = $state->wanderers[$actorRole]['cell'];
            $destination = $direction !== '' && $cell !== null ? Board::neighbor($cell, $direction) : null;

            if ($destination === null
                || ! array_key_exists(Board::key($destination), $state->board)
                || self::entryBlocked($state, $actorRole, $destination)
            ) {
                throw new InvalidMoveException('You cannot flee in that direction.');
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['cell' => $destination])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $destination, 'forced' => true]];

            [$state, $e] = self::moveDarkShacklesGolem($state, $actorRole, $cell, $destination);
            $events = [...$events, ...$e];
        }

        $awaiting = array_values(array_diff($pending['awaiting'], [$actorRole]));

        if ($awaiting !== []) {
            $state = $state->with(['pendingGolemCardChoice' => [...$pending, 'awaiting' => $awaiting]]);

            return [$state, $events];
        }

        $remainingCards = $pending['remaining_cards'];
        $state = $state->with(['pendingGolemCardChoice' => null]);

        [$state, $moreEvents] = self::golemPlayCards($state, $remainingCards, true);
        $events = [...$events, ...$moreEvents];

        if ($state->pendingGolemCardChoice !== null) {
            return [$state, $events]; // hit another forced choice further on
        }

        [$state, $chapelEvents] = self::checkFragileChapelDestruction($state);
        $events = [...$events, ...$chapelEvents];

        return self::finishRoundAfterGolemPhase($state, $events);
    }

    /**
     * Icon of the resurrected: "If every slot on your mat has a Bound
     * item at the end of the game, gain 📜📜." Checked against the
     * physical mat's 5 base slots (body/mind/hand_left/hand_right/bag)
     * only — An expansive mind's extra mind_2/mind_3 aren't part of the
     * physical mat the card refers to. Runs at every point the game can
     * transition to 'finished'.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyEndOfGameScoring(GameState $state): array
    {
        $events = [];
        $slots = ['body', 'mind', 'hand_left', 'hand_right', 'bag'];

        foreach ($state->wanderers as $role => $wanderer) {
            if (! in_array('icon-of-the-resurrected', $wanderer['equipped'], true)) {
                continue;
            }

            $allBound = true;
            foreach ($slots as $slot) {
                $relicKey = $wanderer['equipped'][$slot];

                if (! is_string($relicKey) || RelicCatalog::find($relicKey)?->isBound !== true) {
                    $allBound = false;
                    break;
                }
            }

            if ($allBound) {
                $state = $state->with(['prophecy' => $state->prophecy + 2]);
                $events[] = ['type' => 'prophecy_gained', 'role_key' => $role, 'payload' => ['amount' => 2, 'reason' => 'icon-of-the-resurrected']];
            }
        }

        // Pen: "End of game: If the Golem's mat contains 3+ Glyphs that
        // are not 👺 Glyphs, gain 📜📜📜." Counts non-👺 Glyphs among
        // whatever's active (up to GOLEM_MAX_GLYPHS) — a 👺 Glyph can
        // still be active alongside 3 non-👺 ones and this still scores,
        // since the threshold is "3+ non-👺", not "all active are
        // non-👺". Gated on Pen actually being built, same as every
        // other Location-flavored end-of-game bonus.
        $penBuilt = false;
        foreach ($state->board as $entry) {
            if ($entry['location_key'] === 'pen') {
                $penBuilt = true;
                break;
            }
        }

        if ($penBuilt) {
            $nonSelfInscriptionCount = count(array_filter(
                $state->golem['glyphs'],
                fn (string $glyphKey) => GlyphCatalog::find($glyphKey)?->type !== '👺',
            ));

            if ($nonSelfInscriptionCount >= 3) {
                $state = $state->with(['prophecy' => $state->prophecy + 3]);
                $events[] = ['type' => 'prophecy_gained', 'role_key' => null, 'payload' => ['amount' => 3, 'reason' => 'pen']];
            }
        }

        // Bazaar: "End of game: Gain 📜 for every 4 Relics tucked here."
        foreach ($state->board as $entry) {
            if ($entry['location_key'] !== 'bazaar') {
                continue;
            }

            $gained = intdiv(count($entry['tucked']), 4);

            if ($gained > 0) {
                $state = $state->with(['prophecy' => $state->prophecy + $gained]);
                $events[] = ['type' => 'prophecy_gained', 'role_key' => null, 'payload' => ['amount' => $gained, 'reason' => 'bazaar']];
            }

            break;
        }

        // Cathedral: "End of game: Gain 📜 for each tucked Relic that
        // has 'Divine' in its name."
        foreach ($state->board as $entry) {
            if ($entry['location_key'] !== 'cathedral') {
                continue;
            }

            $gained = count(array_filter(
                $entry['tucked'],
                fn (string $relicKey) => str_contains(RelicCatalog::find($relicKey)->name, 'Divine'),
            ));

            if ($gained > 0) {
                $state = $state->with(['prophecy' => $state->prophecy + $gained]);
                $events[] = ['type' => 'prophecy_gained', 'role_key' => null, 'payload' => ['amount' => $gained, 'reason' => 'cathedral']];
            }

            break;
        }

        // Garbage barricade: "End of game: If there are no Relics
        // face-up or face-down on any Locations, gain 📜📜📜." Checks
        // every board cell's own relic piles (`relics` = face-down,
        // not yet searched; `relics_face_up` = searched but not taken)
        // plus `tucked` (physically laid face-up on that Location,
        // same category as Bazaar/Cathedral/this Location's own tuck
        // pile) — Relics equipped or held by a Wanderer are on the
        // Wanderer, not "on" any Location, so they don't count against
        // this. Gated on Garbage barricade actually being built, same
        // as every other Location-flavored end-of-game bonus.
        $barricadeBuilt = false;
        foreach ($state->board as $entry) {
            if ($entry['location_key'] === 'garbage-barricade') {
                $barricadeBuilt = true;
                break;
            }
        }

        if ($barricadeBuilt) {
            $anyRelicsOnLocations = false;
            foreach ($state->board as $entry) {
                if ($entry['relics'] !== [] || $entry['relics_face_up'] !== [] || $entry['tucked'] !== []) {
                    $anyRelicsOnLocations = true;
                    break;
                }
            }

            if (! $anyRelicsOnLocations) {
                $state = $state->with(['prophecy' => $state->prophecy + 3]);
                $events[] = ['type' => 'prophecy_gained', 'role_key' => null, 'payload' => ['amount' => 3, 'reason' => 'garbage-barricade']];
            }
        }

        // Fragile Chapel: "End of Game: If this is still here gain
        // 📜📜." "Still here" means it was never destroyed by the
        // Golem (see checkFragileChapelDestruction, which clears this
        // same location_key to '' the moment that happens) — simply
        // whether any cell's location_key is still 'fragile-chapel'.
        foreach ($state->board as $entry) {
            if ($entry['location_key'] === 'fragile-chapel') {
                $state = $state->with(['prophecy' => $state->prophecy + 2]);
                $events[] = ['type' => 'prophecy_gained', 'role_key' => null, 'payload' => ['amount' => 2, 'reason' => 'fragile-chapel']];
                break;
            }
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveGolemPhase(GameState $state): array
    {
        $count = $state->golemCardsThisRound();

        // Paradoxical Glyph: "After the Golem plays its first card of
        // the Golem Phase, each Wanderer may 🦶1." This fires outside
        // any Wanderer's actual turn, so it can't just add to
        // actions_remaining (the next turn-start would overwrite it
        // before anyone could spend it) — banked in
        // pending_bonus_actions instead, and added on top of the
        // fresh per-turn amount once that Wanderer's next turn
        // starts (see turnStartActions()). Split off the first card
        // specifically so this doesn't also fire for golemPlayCards()
        // calls elsewhere (Cabalist brand, Reactive, etc.) that
        // aren't the Golem Phase itself.
        if (! in_array('paradoxical', $state->golem['glyphs'], true) || $count <= 0) {
            return self::golemPlayCards($state, $count, true);
        }

        [$state, $events] = self::golemPlayCards($state, 1, true);

        foreach (self::activeWanderers($state) as $role => $cell) {
            $state = $state->with(['wanderers' => $state->wanderersWith($role, [
                'pending_bonus_actions' => $state->wanderers[$role]['pending_bonus_actions'] + 1,
            ])]);
        }

        // The first card already forced a choice (pendingGolemCardChoice
        // is set) — stash the remaining count - 1 cards there instead of
        // playing them now; resolveGolemCardChoice() plays them once
        // everyone's decided.
        if ($state->pendingGolemCardChoice !== null) {
            $state = $state->with(['pendingGolemCardChoice' => [
                ...$state->pendingGolemCardChoice,
                'remaining_cards' => $count - 1,
            ]]);

            return [$state, $events];
        }

        [$state, $restEvents] = self::golemPlayCards($state, $count - 1, true);

        return [$state, [...$events, ...$restEvents]];
    }

    /**
     * Resolves up to $count Golem cards right now, exactly like the
     * Golem phase's own loop (Double Down/Time Paradox included) —
     * factored out so Relics that trigger extra Golem plays outside the
     * normal phase (e.g. Cabalist brand) share the same logic instead
     * of duplicating it.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemPlayCards(GameState $state, int $count, bool $allowPause = false): array
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $golem = $state->golem;

            if ($golem['revealed'] === []) {
                break; // deck + discard both exhausted — nothing left to resolve
            }

            $cardEntry = array_pop($golem['revealed']);
            $golem['discard_pile'][] = $cardEntry['key'];
            $state = $state->with(['golem' => $golem]);

            // Adaptive Glyph: "If a Golem action causes a Wanderer to
            // lose Health, put the card at the bottom of the draw pile
            // instead of discarding it." Snapshot every Wanderer's HP
            // beforehand regardless of card type (Overcharge, for one,
            // deals Health loss with no 👊 in its printed text), then
            // redirect this card's just-pushed discard-pile entry if
            // Adaptive is active and HP actually dropped.
            $adaptiveInPlay = in_array('adaptive', $state->golem['glyphs'], true);
            $hpBeforeAdaptive = $adaptiveInPlay ? array_map(fn (array $w) => $w['hp'], $state->wanderers) : [];

            [$state, $cardEvents, $effects] = self::resolveOrSkipTappedCard($state, $cardEntry, $allowPause);
            $events = [...$events, ...$cardEvents];

            [$state, $forgivingEvents] = self::applyForgivingHeal($state);
            $events = [...$events, ...$forgivingEvents];

            if ($adaptiveInPlay) {
                $anyDamage = false;
                foreach ($state->wanderers as $role => $wanderer) {
                    if ($wanderer['hp'] < ($hpBeforeAdaptive[$role] ?? $wanderer['hp'])) {
                        $anyDamage = true;
                        break;
                    }
                }

                if ($anyDamage) {
                    $golem = $state->golem;
                    $discardIndex = array_search($cardEntry['key'], $golem['discard_pile'], true);

                    if ($discardIndex !== false) {
                        unset($golem['discard_pile'][$discardIndex]);
                        $golem['discard_pile'] = array_values($golem['discard_pile']);
                        $golem['draw_pile'][] = ['key' => $cardEntry['key'], 'tapped' => false];
                        $state = $state->with(['golem' => $golem]);
                    }
                }
            }

            if ($effects['double_next'] && $i + 1 < $count) {
                // "Perform the next Golem card twice" — doubles the next
                // physical card's effect without consuming an extra one of
                // this round's N plays; the loop's own i++ still advances
                // onto it normally afterward.
                $golem = $state->golem;
                if ($golem['revealed'] !== []) {
                    $nextEntry = $golem['revealed'][array_key_last($golem['revealed'])];
                    [$state, $extraEvents] = self::resolveOrSkipTappedCard($state, $nextEntry);
                    $events = [...$events, ...$extraEvents];

                    [$state, $forgivingEvents] = self::applyForgivingHeal($state);
                    $events = [...$events, ...$forgivingEvents];
                }
            }

            if ($effects['extra_from_bottom'] > 0) {
                [$state, $bottomEvents] = self::golemPlayFromBottom($state, $effects['extra_from_bottom']);
                $events = [...$events, ...$bottomEvents];
            }

            $state = self::refillGolemRevealed($state);

            // Fire storm/Roar/Sacrifice of Possessions each force every
            // affected Wanderer to choose something (which Relic to
            // trash, which direction to flee) — parked on
            // pendingGolemCardChoice instead of auto-picking, same
            // can't-decide-it-for-them problem as every other pendingXxx
            // field, but this one pauses the Golem's own card-resolution
            // loop rather than a single Relic's. Only honored when
            // $allowPause is true (the Golem's own phase) — Relic/Glyph-
            // triggered extra plays elsewhere (Cabalist brand, Reactive,
            // Time Paradox's bottom draws, Double Down's doubled card)
            // keep the old auto-pick simplification rather than also
            // having to pause and resume THEIR OWN surrounding
            // post-processing, which this doesn't attempt to support.
            if ($allowPause && $state->pendingGolemCardChoice !== null) {
                $state = $state->with(['pendingGolemCardChoice' => [
                    ...$state->pendingGolemCardChoice,
                    'remaining_cards' => $count - $i - 1,
                ]]);
                break;
            }
        }

        return [$state, $events];
    }

    /**
     * Time Paradox: "Draw and resolve 2 additional Golem cards from the
     * bottom of the Golem deck after this." Unlike every other "extra
     * cards" effect, these come straight from the bottom of draw_pile
     * (array_pop, not the normal top-down array_shift refill) and never
     * touch the revealed row at all.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemPlayFromBottom(GameState $state, int $count): array
    {
        $events = [];

        for ($i = 0; $i < $count; $i++) {
            $golem = $state->golem;

            if ($golem['draw_pile'] === []) {
                if ($golem['discard_pile'] === []) {
                    break; // deck + discard both exhausted — nothing left to resolve
                }

                $golem['draw_pile'] = array_map(
                    fn (string $key) => ['key' => $key, 'tapped' => false],
                    $golem['discard_pile']
                );
                shuffle($golem['draw_pile']);
                $golem['discard_pile'] = [];
            }

            $cardEntry = array_pop($golem['draw_pile']);
            $golem['discard_pile'][] = $cardEntry['key'];
            $state = $state->with(['golem' => $golem]);

            [$state, $cardEvents] = self::resolveOrSkipTappedCard($state, $cardEntry);
            $events = [...$events, ...$cardEvents];

            [$state, $forgivingEvents] = self::applyForgivingHeal($state);
            $events = [...$events, ...$forgivingEvents];
        }

        return [$state, $events];
    }

    /**
     * Forgiving: "Every time the Golem plays a card, all Wanderers
     * 🌿1." Fires for every card resolution, tapped or untapped — same
     * "plays a card" reading Defenders' Guild already uses.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyForgivingHeal(GameState $state): array
    {
        if (! in_array('forgiving', $state->golem['glyphs'], true)) {
            return [$state, []];
        }

        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 1);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * @param  array{key: string, tapped: bool}  $cardEntry
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: array{double_next: bool, extra_from_bottom: int}}
     */
    private static function resolveOrSkipTappedCard(GameState $state, array $cardEntry, bool $allowPause = false): array
    {
        $card = GolemCardCatalog::find($cardEntry['key']);
        $isAttackCard = $card !== null && str_contains($card->action, '👊');
        $hpBefore = $isAttackCard ? array_map(fn (array $w) => $w['hp'], $state->wanderers) : [];

        // Poison Charm: promote any queued reduction to "active" right as
        // this card starts resolving, so it's this card's damage (not the
        // one that armed it, nor some later one) that gets reduced.
        if ($isAttackCard) {
            $state = $state->with(['golem' => $state->golemWith([
                'active_attack_reduction' => $state->golem['armed_attack_reduction'],
                'armed_attack_reduction' => 0,
            ])]);
        }

        if ($cardEntry['tapped']) {
            [$state, $events, $effects] = [$state, [
                ['type' => 'golem_card_tapped', 'role_key' => null, 'payload' => ['card_key' => $cardEntry['key'], 'name' => $card?->name]],
            ], ['double_next' => false, 'extra_from_bottom' => 0]];

            // Fearful Glyph: "When the Golem plays a tapped card, it
            // moves 1 space away from Wanderers if possible."
            if (in_array('fearful', $state->golem['glyphs'], true)) {
                [$state, $fearfulEvents] = self::golemMoveAwayFromWanderers($state);
                $events = [...$events, ...$fearfulEvents];
            }
        } else {
            [$state, $events, $effects] = self::resolveGolemCard($state, $cardEntry['key'], $allowPause);
        }

        if ($isAttackCard) {
            [$state, $tuckEvents] = self::maybeTuckAtDefendersGuild($state, $cardEntry['key'], $hpBefore);
            $events = [...$events, ...$tuckEvents];
            $state = $state->with(['golem' => $state->golemWith(['active_attack_reduction' => 0])]);
        }

        return [$state, $events, $effects];
    }

    /**
     * Defenders' Guild: "Ongoing: When the Golem plays a card (tapped or
     * untapped) with 👊 but Wanderers lose 0 health, tuck the Golem card
     * here. When 4 cards are tucked, return them to the Golem discard
     * pile, and gain 📜📜." Detected generically via an HP-diff snapshot
     * (same trick as Divine Icon/Golem sanctuary) rather than special-
     * casing which attack cards can deal 0 — a tapped card, a card with
     * no Wanderer in range, or Cabalist's Gown immunity all qualify.
     *
     * @param  array<string, int>  $hpBefore
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function maybeTuckAtDefendersGuild(GameState $state, string $cardKey, array $hpBefore): array
    {
        foreach ($state->wanderers as $role => $wanderer) {
            if ($wanderer['hp'] < ($hpBefore[$role] ?? $wanderer['hp'])) {
                return [$state, []];
            }
        }

        $guildKey = null;
        foreach ($state->board as $key => $entry) {
            if ($entry['location_key'] === 'defenders-guild') {
                $guildKey = $key;
                break;
            }
        }

        if ($guildKey === null) {
            return [$state, []];
        }

        $board = $state->board;
        $tucked = [...$board[$guildKey]['tucked'], $cardKey];
        $events = [['type' => 'golem_card_tucked', 'role_key' => null, 'payload' => ['card_key' => $cardKey, 'location' => 'defenders-guild']]];

        if (count($tucked) >= 4) {
            $golem = $state->golem;
            $golem['discard_pile'] = [...$golem['discard_pile'], ...$tucked];
            $tucked = [];
            $state = $state->with(['golem' => $golem, 'prophecy' => $state->prophecy + 2]);
            $events[] = ['type' => 'defenders_guild_cards_returned', 'role_key' => null, 'payload' => []];
            $events[] = ['type' => 'prophecy_gained', 'role_key' => null, 'payload' => ['amount' => 2]];
        }

        $board = $state->board;
        $board[$guildKey] = [...$board[$guildKey], 'tucked' => $tucked];
        $state = $state->with(['board' => $board]);

        return [$state, $events];
    }

    /**
     * Keeps the revealed row topped up to 3 after each resolution,
     * reshuffling the discard pile back into the draw pile if it runs dry
     * — same rule as the Location deck. Cards reshuffled back in are
     * always untapped; being tapped only ever applies within the current
     * draw pile.
     */
    private static function refillGolemRevealed(GameState $state): GameState
    {
        $golem = $state->golem;

        while (count($golem['revealed']) < self::GOLEM_REVEALED_SIZE) {
            if ($golem['draw_pile'] === []) {
                if ($golem['discard_pile'] === []) {
                    break;
                }

                $golem['draw_pile'] = array_map(
                    fn (string $key) => ['key' => $key, 'tapped' => false],
                    $golem['discard_pile']
                );
                shuffle($golem['draw_pile']);
                $golem['discard_pile'] = [];
            }

            $golem['revealed'][] = array_shift($golem['draw_pile']);
        }

        return $state->with(['golem' => $golem]);
    }

    /**
     * Executes one Golem card. Only a hand-picked subset has real,
     * mechanical behavior (movement/attack defaults, plus the named cases
     * below) — every other card is logged with its full text so it's
     * still visible to read and adjudicate by hand, but doesn't move
     * anything.
     *
     * Simplification: when an attack would hit multiple Wanderers on one
     * space, every one of them takes the damage, rather than pausing the
     * phase for the Emperor to pick one (rules.md says the Emperor
     * chooses) — there's no mid-phase interactive decision point built
     * yet. Exhausted (0 HP) Wanderers are never targeted or damaged.
     *
     * Focused Glyph only overrides single-target selection (Stalk,
     * Pursue, Charge/Lurch/Pound's implicit "nearest," Targeted
     * attack) — area-effect cards (Storm, Dissent, Overcharge, etc.)
     * still hit every Wanderer in range regardless of Focused, since
     * "effectively invisible to it" isn't modeled as excluding them
     * from area effects, only from being chosen as a specific target.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: array{double_next: bool, extra_from_bottom: int}}
     */
    private static function resolveGolemCard(GameState $state, string $cardKey, bool $allowPause = false): array
    {
        $card = GolemCardCatalog::find($cardKey);
        $baseKey = (string) preg_replace('/-\d+$/', '', $cardKey);
        $effects = ['double_next' => false, 'extra_from_bottom' => 0];

        $events = [
            ['type' => 'golem_card_resolved', 'role_key' => null, 'payload' => [
                'card_key' => $cardKey, 'name' => $card?->name, 'action' => $card?->action,
            ]],
        ];

        switch ($baseKey) {
            case 'charge':
                [$state, $e] = self::golemMoveTowardNearest($state, 2);
                $events = [...$events, ...$e];
                [$state, $e] = self::golemAttackHere($state, 3);
                $events = [...$events, ...$e];
                break;

            case 'lurch':
                [$state, $e] = self::golemMoveTowardNearest($state, 1);
                $events = [...$events, ...$e];
                [$state, $e] = self::golemAttackHere($state, 4);
                $events = [...$events, ...$e];
                break;

            case 'pound':
                [$state, $e] = self::golemMoveTowardNearest($state, 1);
                $events = [...$events, ...$e];
                [$state, $e] = self::golemAttackHere($state, 5);
                $events = [...$events, ...$e];
                break;

            case 'deaths-door':
                [$state, $e] = self::golemSetHpOnAndAdjacent($state, 1);
                $events = [...$events, ...$e];
                break;

            case 'dissent':
                [$state, $e] = self::golemDamageSharedCells($state, 3);
                $events = [...$events, ...$e];
                break;

            case 'storm':
                [$state, $e] = self::golemDamageAllWanderers($state, 2);
                $events = [...$events, ...$e];
                break;

            case 'spew':
                [$state, $e] = self::golemDamageWithinDistance($state, 2, 2);
                $events = [...$events, ...$e];
                break;

            case 'stalk':
                [$state, $e] = self::golemMoveToward($state, self::leastHpWanderer($state), 5);
                $events = [...$events, ...$e];
                break;

            case 'pursue':
                [$state, $e] = self::golemMoveToward($state, self::farthestWanderer($state), 5);
                $events = [...$events, ...$e];
                [$state, $e] = self::golemAttackHere($state, 2);
                $events = [...$events, ...$e];
                break;

            case 'self-inscription':
                [$state, $e] = self::addGlyphOfType($state, '👺');
                $events = [...$events, ...$e];
                break;

            case 'scar':
                $target = self::nearestWanderer($state);
                [$state, $e] = self::golemMoveToward($state, $target, 1);
                $events = [...$events, ...$e];

                if ($target !== null) {
                    [$state, $e] = self::applyBurn($state, $target);
                    $events = [...$events, ...$e];
                }
                break;

            case 'public-lashings':
                $target = self::mostShameWanderer($state);
                [$state, $e] = self::golemMoveToward($state, $target, 1);
                $events = [...$events, ...$e];

                if ($target !== null && self::golemSharesCellWith($state, $target)) {
                    $shame = $state->wanderers[$target]['shame'];

                    if ($shame > 0) {
                        [$state, $damageEvents] = self::applyDamage($state, $target, 3 * $shame, true);
                        $events = [...$events, ...$damageEvents];
                    }
                }
                break;

            case 'public-shaming':
                foreach (self::activeWanderers($state) as $role => $cell) {
                    $shame = $state->wanderers[$role]['shame'];

                    if ($shame === 0) {
                        continue;
                    }

                    [$state, $damageEvents] = self::applyDamage($state, $role, 2 * $shame, true);
                    $events = [...$events, ...$damageEvents];
                }
                break;

            case 'penitence':
                $state = $state->with(['golem' => $state->golemWith(['shame' => max(0, $state->golem['shame'] - 1)])]);
                break;

            case 'double-down':
                $effects['double_next'] = true;
                break;

            case 'time-paradox':
                $effects['extra_from_bottom'] = 2;
                break;

            case 'deface':
                [$state, $e] = self::golemDeface($state);
                $events = [...$events, ...$e];
                break;

            case 'fire-storm':
                [$state, $e] = self::golemFireStorm($state, $allowPause);
                $events = [...$events, ...$e];
                break;

            case 'great-sweeping-scythe':
                [$state, $e] = self::golemGreatSweepingScythe($state);
                $events = [...$events, ...$e];
                break;

            case 'overcharge':
                [$state, $e] = self::golemOvercharge($state);
                $events = [...$events, ...$e];
                break;

            case 'quake':
                [$state, $e] = self::golemQuake($state);
                $events = [...$events, ...$e];
                break;

            case 'roar':
                [$state, $e] = self::golemRoar($state, $allowPause);
                $events = [...$events, ...$e];
                break;

            case 'sacrifice-of-possessions':
                [$state, $e] = self::golemSacrificeOfPossessions($state, $allowPause);
                $events = [...$events, ...$e];
                break;

            case 'shard-devourer':
                [$state, $e] = self::golemShardDevourer($state);
                $events = [...$events, ...$e];
                break;

            case 'siege':
                [$state, $e] = self::golemSiege($state);
                $events = [...$events, ...$e];
                break;

            case 'summoning-roar':
                [$state, $e] = self::golemSummoningRoar($state);
                $events = [...$events, ...$e];
                break;

            case 'targeted-attack':
                [$state, $e] = self::golemTargetedAttack($state);
                $events = [...$events, ...$e];
                break;

            case 'rage-against-time':
                [$state, $e] = self::golemAttackHere($state, $state->prophecy);
                $events = [...$events, ...$e];
                break;

            case 'whirlwind':
                [$state, $e] = self::golemWhirlwind($state);
                $events = [...$events, ...$e];
                break;

            case 'dark-swell':
                [$state, $e] = self::golemDarkSwell($state);
                $events = [...$events, ...$e];
                break;

            case 'health-inversion':
                [$state, $e] = self::golemHealthInversion($state);
                $events = [...$events, ...$e];
                break;
        }

        return [$state, $events, $effects];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemMoveTowardNearest(GameState $state, int $maxSteps): array
    {
        return self::golemMoveToward($state, self::nearestWanderer($state), $maxSteps);
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemMoveToward(GameState $state, ?string $targetRole, int $maxSteps): array
    {
        if ($targetRole === null) {
            return [$state, []];
        }

        return self::golemMoveTowardCell($state, $state->wanderers[$targetRole]['cell'], $maxSteps);
    }

    /**
     * @param  array{0: int, 1: int}|null  $targetCell
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemMoveTowardCell(GameState $state, ?array $targetCell, int $maxSteps): array
    {
        $golemCell = $state->golem['cell'];

        if ($targetCell === null || $golemCell === null) {
            return [$state, []];
        }

        // Movement Glyphs: Mobile (+1🦶), Relentless (+2🦶), Chained
        // (-3🦶), Contemplative (-1🦶) — all modify the Golem's own
        // step budget for every move it makes, floored at 0.
        $glyphs = $state->golem['glyphs'];
        if (in_array('mobile', $glyphs, true)) {
            $maxSteps += 1;
        }
        if (in_array('relentless', $glyphs, true)) {
            $maxSteps += 2;
        }
        if (in_array('chained', $glyphs, true)) {
            $maxSteps -= 3;
        }
        if (in_array('contemplative', $glyphs, true)) {
            $maxSteps -= 1;
        }
        $maxSteps = max(0, $maxSteps);

        $path = Board::shortestPath($golemCell, $targetCell, $state->board, $state->wallKeys());

        if ($path === null || $path === []) {
            return [$state, []];
        }

        $steps = array_slice($path, 0, $maxSteps);

        if ($steps === []) {
            return [$state, []];
        }

        $destination = $steps[array_key_last($steps)];

        $state = $state->with(['golem' => $state->golemWith(['cell' => $destination])]);
        $events = [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $destination]]];

        // Destructive Glyph: "When the Golem moves into a space
        // containing Infrastructure, it destroys it immediately."
        // Checked against the final destination only — Golem movement
        // is already modeled as one atomic jump (a single golem_moved
        // event), not per-step, elsewhere in this engine.
        if (in_array('destructive', $glyphs, true)) {
            $destinationKey = Board::key($destination);
            $infrastructure = $state->board[$destinationKey]['infrastructure'];

            if ($infrastructure !== []) {
                $pool = $state->infrastructurePool;
                foreach ($infrastructure as $type) {
                    $pool[$type]++;
                    $events[] = ['type' => 'infrastructure_removed', 'role_key' => null, 'payload' => ['cell' => $destination, 'infrastructure_type' => $type]];
                }

                $board = $state->board;
                $board[$destinationKey] = [...$board[$destinationKey], 'infrastructure' => []];
                $state = $state->with(['board' => $board, 'infrastructurePool' => $pool]);
            }
        }

        [$state, $e] = self::moveDarkShacklesWanderers($state, $golemCell, $destination);
        $events = [...$events, ...$e];

        return [$state, $events];
    }

    /**
     * Fearful Glyph: "When the Golem plays a tapped card, it moves 1
     * space away from Wanderers if possible." Mirrors golemRoar's own
     * farthest-neighbor selection, but for the Golem's own position
     * instead of pushing a Wanderer — picks whichever occupied,
     * non-Walled neighbor maximizes distance to the nearest Wanderer;
     * stays put if no neighbor improves on its current distance.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemMoveAwayFromWanderers(GameState $state): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $wallKeys = $state->wallKeys();
        $bestDistance = self::distanceToNearestWanderer($state, $golemCell);
        $best = null;

        foreach (Board::occupiedNeighbors($golemCell, $state->board) as $option) {
            if (in_array(Board::key($option), $wallKeys, true)) {
                continue;
            }

            $distance = self::distanceToNearestWanderer($state, $option);

            if ($distance > $bestDistance) {
                $best = $option;
                $bestDistance = $distance;
            }
        }

        if ($best === null) {
            return [$state, []];
        }

        $state = $state->with(['golem' => $state->golemWith(['cell' => $best])]);

        return [$state, [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $best]]]];
    }

    /**
     * @param  array{0: int, 1: int}  $cell
     */
    private static function distanceToNearestWanderer(GameState $state, array $cell): int
    {
        $min = null;

        foreach (self::activeWanderers($state) as $otherCell) {
            $distance = Board::distance($cell, $otherCell, $state->board) ?? 0;

            if ($min === null || $distance < $min) {
                $min = $distance;
            }
        }

        return $min ?? 0;
    }

    /**
     * Dark shackles: "Bound (🪡) If you are on the Golem's: you move
     * with it when it moves, and it moves with you when you move."
     * This is the Golem-moves-the-wanderer half. Checked after every
     * real Golem movement (not removal/teleport-to-nowhere, like
     * Poof).
     *
     * @param  array{0: int, 1: int}  $fromCell
     * @param  array{0: int, 1: int}  $toCell
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function moveDarkShacklesWanderers(GameState $state, array $fromCell, array $toCell): array
    {
        $fromKey = Board::key($fromCell);
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) !== $fromKey || ! in_array('dark-shackles', $state->wanderers[$role]['equipped'], true)) {
                continue;
            }

            if (self::entryBlocked($state, $role, $toCell)) {
                continue;
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $toCell])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $toCell, 'forced' => true]];
        }

        return [$state, $events];
    }

    /**
     * Dark shackles: "Bound (🪡) If you are on the Golem's: you move
     * with it when it moves, and it moves with you when you move."
     * This is the wanderer-moves-the-Golem half, called after ANY
     * wanderer cell change (voluntary or forced) for the wanderer who
     * actually moved. Triggers only if that wanderer has Dark
     * shackles equipped AND was standing on the Golem's cell
     * immediately before this move. If two different Dark-shackled
     * wanderers are both forced to move apart from the Golem's space
     * in the same resolution, the Golem follows whichever one's move
     * is processed last — there is no rule for that case, so this
     * picks last-write-wins rather than guessing a priority order.
     *
     * @param  array{0: int, 1: int}  $fromCell
     * @param  array{0: int, 1: int}  $toCell
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function moveDarkShacklesGolem(GameState $state, string $role, array $fromCell, array $toCell): array
    {
        if (! isset($state->wanderers[$role]) || ! in_array('dark-shackles', $state->wanderers[$role]['equipped'], true)) {
            return [$state, []];
        }

        $golemCell = $state->golem['cell'];

        if ($golemCell === null || Board::key($golemCell) !== Board::key($fromCell) || Board::key($fromCell) === Board::key($toCell)) {
            return [$state, []];
        }

        $state = $state->with(['golem' => $state->golemWith(['cell' => $toCell])]);

        return [$state, [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $toCell, 'forced' => true]]]];
    }

    /**
     * Nearest board cell (by Golem pathing distance) that has at least
     * one piece of $type Infrastructure on it.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function nearestCellWithInfrastructure(GameState $state, string $type): ?array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return null;
        }

        $best = null;
        $bestDistance = null;

        foreach ($state->board as $key => $entry) {
            if (! in_array($type, $entry['infrastructure'], true)) {
                continue;
            }

            $cell = Board::fromKey($key);
            $distance = Board::distance($golemCell, $cell, $state->board, $state->wallKeys());

            if ($distance === null) {
                continue;
            }

            if ($bestDistance === null || $distance < $bestDistance) {
                $best = $cell;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private static function golemCellsOnAndAdjacent(GameState $state): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [];
        }

        return [$golemCell, ...Board::occupiedNeighbors($golemCell, $state->board)];
    }

    /**
     * Trashes the first (any) equipped Relic found on a Wanderer — used
     * by Golem cards that make every affected Wanderer trash a Relic
     * without specifying which. Real rules.md text leaves that choice to
     * the Wanderer; auto-picking the first slot is the same kind of
     * simplification as applyBurn's slot auto-pick, for the same
     * reason (no mid-phase interactive-pause system exists yet).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function trashFirstEquippedRelic(GameState $state, string $role): array
    {
        foreach ($state->wanderers[$role]['equipped'] as $value) {
            if ($value !== null && ! self::isStatusToken($value)) {
                $state = self::trashEquippedRelic($state, $role, $value);

                return [$state, [['type' => 'relic_trashed', 'role_key' => $role, 'payload' => ['relic_key' => $value]]]];
            }
        }

        return [$state, []];
    }

    /**
     * Deface: "The Emperor trashes the next face-down Relic from the
     * Golem's space." Picks the top (first) of the pile, since there's
     * no interactive choice system for which one.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemDeface(GameState $state): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $cellKey = Board::key($golemCell);
        $boardEntry = $state->board[$cellKey] ?? null;

        if ($boardEntry === null || $boardEntry['relics'] === []) {
            return [$state, []];
        }

        $relics = $boardEntry['relics'];
        $trashed = array_shift($relics);
        $board = $state->board;
        $board[$cellKey] = [...$boardEntry, 'relics' => $relics];
        $state = $state->with(['board' => $board]);

        return [$state, [['type' => 'relic_trashed_from_board', 'role_key' => 'emperor', 'payload' => ['relic_key' => $trashed, 'cell' => $golemCell]]]];
    }

    /**
     * Fire storm: 👊3 to Wanderers on/adjacent to the Golem's space; each
     * who lost Health as a result trashes one equipped Relic. When
     * $allowPause is true (the Golem's own phase), that choice is a
     * real pendingGolemCardChoice instead of auto-picking the first
     * slot — see golemPlayCards()'s own doc comment for the scope of
     * where $allowPause is actually honored.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemFireStorm(GameState $state, bool $allowPause = false): array
    {
        $cellKeys = array_map(Board::key(...), self::golemCellsOnAndAdjacent($state));
        $events = [];
        $mustTrash = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            $hpBefore = $state->wanderers[$role]['hp'];
            [$state, $damageEvents] = self::applyDamage($state, $role, 3, true);
            $events = [...$events, ...$damageEvents];

            // "Each Wanderer who lost Health as a result" — checked
            // against actual HP lost, not just hpBefore > 0, since Dark
            // Relics/Poison Charm can reduce this hit's real damage to 0.
            if ($state->wanderers[$role]['hp'] < $hpBefore) {
                $mustTrash[] = $role;
            }
        }

        return self::forceRelicTrashChoice($state, $events, $mustTrash, $allowPause, 'fire-storm');
    }

    /**
     * Shared tail for Fire storm/Sacrifice of Possessions: every role
     * in $mustTrash has at least one real Relic to lose. With
     * $allowPause, parks it on pendingGolemCardChoice (only for
     * whichever of those roles actually has a non-token Relic equipped
     * — nothing to choose between otherwise); without it, falls back
     * to the pre-existing auto-pick.
     *
     * @param  list<string>  $mustTrash
     * @param  list<array{type: string, role_key: ?string, payload: array<string, mixed>}>  $events
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function forceRelicTrashChoice(GameState $state, array $events, array $mustTrash, bool $allowPause, string $cardKey): array
    {
        if (! $allowPause) {
            foreach ($mustTrash as $role) {
                [$state, $e] = self::trashFirstEquippedRelic($state, $role);
                $events = [...$events, ...$e];
            }

            return [$state, $events];
        }

        $awaiting = array_values(array_filter(
            $mustTrash,
            fn (string $role) => count(array_filter(
                $state->wanderers[$role]['equipped'],
                fn (mixed $v) => $v !== null && ! self::isStatusToken($v)
            )) > 0
        ));

        if ($awaiting !== []) {
            $state = $state->with(['pendingGolemCardChoice' => [
                'type' => 'trash_relic',
                'card_key' => $cardKey,
                'awaiting' => $awaiting,
                'remaining_cards' => 0,
            ]]);
        }

        return [$state, $events];
    }

    /**
     * Eruption (key frozen as 'great-sweeping-scythe' from before this
     * card was renamed): 👊5 to Wanderers on/adjacent to the Golem's
     * space; give a Burn token to anyone who lost Health.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemGreatSweepingScythe(GameState $state): array
    {
        $cellKeys = array_map(Board::key(...), self::golemCellsOnAndAdjacent($state));
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            $hpBefore = $state->wanderers[$role]['hp'];
            [$state, $damageEvents] = self::applyDamage($state, $role, 5, true);
            $events = [...$events, ...$damageEvents];

            // "Give a Burn token to all Wanderers who lost Health" —
            // checked against actual HP lost, not just hpBefore > 0, since
            // Dark Relics/Poison Charm can reduce this hit's real damage
            // to 0.
            if ($state->wanderers[$role]['hp'] < $hpBefore) {
                [$state, $e] = self::applyBurn($state, $role);
                $events = [...$events, ...$e];
            }
        }

        return [$state, $events];
    }

    /**
     * Overcharge: Wanderers on a space with a Monolith Shard lose 1
     * Health for each Monolith Shard on or adjacent to their Location.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemOvercharge(GameState $state): array
    {
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $entry = $state->board[Board::key($cell)];

            if (! in_array('monolith_shard', $entry['infrastructure'], true)) {
                continue;
            }

            $count = count(array_filter($entry['infrastructure'], fn (string $i) => $i === 'monolith_shard'));

            foreach (Board::occupiedNeighbors($cell, $state->board) as $neighbor) {
                $neighborEntry = $state->board[Board::key($neighbor)];
                $count += count(array_filter($neighborEntry['infrastructure'], fn (string $i) => $i === 'monolith_shard'));
            }

            [$state, $damageEvents] = self::applyDamage($state, $role, $count, true);
            $events = [...$events, ...$damageEvents];
        }

        return [$state, $events];
    }

    /**
     * Quake: 🦶2 to the nearest Road. All Wanderers on any Road-bearing
     * space lose 2 Health. Return all Infrastructure from the targeted
     * Road's space to the Emperor's pool.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemQuake(GameState $state): array
    {
        $target = self::nearestCellWithInfrastructure($state, 'road');
        $events = [];

        [$state, $e] = self::golemMoveTowardCell($state, $target, 2);
        $events = [...$events, ...$e];

        foreach ($state->board as $key => $entry) {
            if (! in_array('road', $entry['infrastructure'], true)) {
                continue;
            }

            foreach (self::activeWanderers($state) as $role => $wandererCell) {
                if (Board::key($wandererCell) !== $key) {
                    continue;
                }

                [$state, $damageEvents] = self::applyDamage($state, $role, 2, true);
                $events = [...$events, ...$damageEvents];
            }
        }

        if ($target !== null) {
            $targetKey = Board::key($target);
            $entry = $state->board[$targetKey];
            $pool = $state->infrastructurePool;

            foreach ($entry['infrastructure'] as $infrastructureType) {
                $pool[$infrastructureType] = ($pool[$infrastructureType] ?? 0) + 1;
            }

            $board = $state->board;
            $board[$targetKey] = [...$entry, 'infrastructure' => []];
            $state = $state->with(['board' => $board, 'infrastructurePool' => $pool]);
            $events[] = ['type' => 'infrastructure_returned', 'role_key' => null, 'payload' => ['cell' => $target]];
        }

        return [$state, $events];
    }

    /**
     * Roar: 👊3 to Wanderers on/adjacent to the Golem's space; each must
     * then move 1 space away. With $allowPause (the Golem's own phase),
     * "a direction they choose" is a real pendingGolemCardChoice;
     * without it, simplified to the neighboring space farthest from the
     * Golem (ties broken by iteration order) — same scope as
     * golemFireStorm()'s $allowPause.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemRoar(GameState $state, bool $allowPause = false): array
    {
        $golemCell = $state->golem['cell'];
        $cellKeys = array_map(Board::key(...), self::golemCellsOnAndAdjacent($state));
        $events = [];
        $affectedRoles = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            [$state, $damageEvents] = self::applyDamage($state, $role, 3, true);
            $events = [...$events, ...$damageEvents];
            $affectedRoles[] = $role;
        }

        // Only roles with at least one valid space to flee to have a
        // real choice to make — everyone else (fully boxed in by
        // Catacombs/no neighbors) just doesn't move, same as before.
        $awaiting = array_values(array_filter(
            $affectedRoles,
            function (string $role) use ($state) {
                foreach (Board::occupiedNeighbors($state->wanderers[$role]['cell'], $state->board) as $option) {
                    if (! self::entryBlocked($state, $role, $option)) {
                        return true;
                    }
                }

                return false;
            }
        ));

        if ($allowPause) {
            if ($awaiting !== []) {
                $state = $state->with(['pendingGolemCardChoice' => [
                    'type' => 'move_direction',
                    'card_key' => 'roar',
                    'awaiting' => $awaiting,
                    'remaining_cards' => 0,
                ]]);
            }

            return [$state, $events];
        }

        foreach ($awaiting as $role) {
            $cell = $state->wanderers[$role]['cell'];
            $best = null;
            $bestDistance = -1;

            foreach (Board::occupiedNeighbors($cell, $state->board) as $option) {
                if (self::entryBlocked($state, $role, $option)) {
                    continue;
                }

                $distance = $golemCell === null ? 0 : (Board::distance($golemCell, $option, $state->board) ?? 0);

                if ($distance > $bestDistance) {
                    $best = $option;
                    $bestDistance = $distance;
                }
            }

            if ($best === null) {
                continue;
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $best])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $best, 'forced' => true]];

            [$state, $e] = self::moveDarkShacklesGolem($state, $role, $cell, $best);
            $events = [...$events, ...$e];
        }

        return [$state, $events];
    }

    /**
     * Sacrifice of Possessions: every Wanderer trashes one equipped
     * Relic. See golemFireStorm()/forceRelicTrashChoice() for the
     * $allowPause behavior.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemSacrificeOfPossessions(GameState $state, bool $allowPause = false): array
    {
        $mustTrash = array_keys(self::activeWanderers($state));

        return self::forceRelicTrashChoice($state, [], $mustTrash, $allowPause, 'sacrifice-of-possessions');
    }

    /**
     * Shard Devourer: 🦶2 to the nearest Monolith Shard, remove it, and
     * 👊2 all Wanderers on or adjacent to its space.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemShardDevourer(GameState $state): array
    {
        $target = self::nearestCellWithInfrastructure($state, 'monolith_shard');
        $events = [];

        [$state, $e] = self::golemMoveTowardCell($state, $target, 2);
        $events = [...$events, ...$e];

        if ($target === null) {
            return [$state, $events];
        }

        $targetKey = Board::key($target);
        $entry = $state->board[$targetKey];
        $infrastructure = $entry['infrastructure'];
        $index = array_search('monolith_shard', $infrastructure, true);

        if ($index !== false) {
            unset($infrastructure[$index]);
            $board = $state->board;
            $board[$targetKey] = [...$entry, 'infrastructure' => array_values($infrastructure)];
            $state = $state->with(['board' => $board]);
            $events[] = ['type' => 'infrastructure_removed', 'role_key' => null, 'payload' => ['cell' => $target, 'infrastructure_type' => 'monolith_shard']];
        }

        [$state, $damageEvents] = self::damageWanderersAt($state, [$target, ...Board::occupiedNeighbors($target, $state->board)], 2, true);
        $events = [...$events, ...$damageEvents];

        return [$state, $events];
    }

    /**
     * Siege: 🦶2 to be adjacent to the nearest Wall; all Wanderers
     * adjacent to that Wall lose 4 Health. The Golem can never stand on
     * a Walled space, so "nearest Wall" is resolved as the nearest
     * occupied, non-Walled neighbor of any Wall.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemSiege(GameState $state): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $bestWall = null;
        $bestApproach = null;
        $bestDistance = null;

        foreach ($state->wallKeys() as $wallKey) {
            $wallCell = Board::fromKey($wallKey);

            foreach (Board::occupiedNeighbors($wallCell, $state->board, $state->wallKeys()) as $approach) {
                $distance = Board::distance($golemCell, $approach, $state->board, $state->wallKeys());

                if ($distance === null) {
                    continue;
                }

                if ($bestDistance === null || $distance < $bestDistance) {
                    $bestDistance = $distance;
                    $bestWall = $wallCell;
                    $bestApproach = $approach;
                }
            }
        }

        $events = [];

        if ($bestApproach !== null) {
            [$state, $e] = self::golemMoveTowardCell($state, $bestApproach, 2);
            $events = [...$events, ...$e];
        }

        if ($bestWall !== null) {
            [$state, $damageEvents] = self::damageWanderersAt($state, Board::occupiedNeighbors($bestWall, $state->board), 4, true);
            $events = [...$events, ...$damageEvents];
        }

        return [$state, $events];
    }

    /**
     * Summoning Roar: all Wanderers 🦶2 toward the Golem's space, then
     * 👊3 to whoever ends up sharing it.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemSummoningRoar(GameState $state): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $path = Board::shortestPath($cell, $golemCell, $state->board);

            if ($path === null || $path === []) {
                continue;
            }

            $steps = array_slice($path, 0, 2);
            $destination = $steps[array_key_last($steps)];

            if (self::entryBlocked($state, $role, $destination)) {
                continue;
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $destination])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $destination, 'forced' => true]];

            [$state, $e] = self::moveDarkShacklesGolem($state, $role, $cell, $destination);
            $events = [...$events, ...$e];
        }

        [$state, $damageEvents] = self::damageWanderersAt($state, [$golemCell], 3, true);
        $events = [...$events, ...$damageEvents];

        return [$state, $events];
    }

    /**
     * Targeted attack: 🦶2 to the nearest Wanderer. If they have an Ally
     * on or adjacent to their space, 👊5 to each of them.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemTargetedAttack(GameState $state): array
    {
        $target = self::nearestWanderer($state);
        $events = [];

        [$state, $e] = self::golemMoveToward($state, $target, 2);
        $events = [...$events, ...$e];

        if ($target === null) {
            return [$state, $events];
        }

        $targetCell = $state->wanderers[$target]['cell'];

        if ($targetCell === null) {
            return [$state, $events];
        }

        $allies = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if ($role === $target) {
                continue;
            }

            $distance = Board::distance($targetCell, $cell, $state->board);

            if ($distance !== null && $distance <= 1) {
                $allies[] = $role;
            }
        }

        if ($allies === []) {
            return [$state, $events];
        }

        [$state, $damageEvents] = self::applyDamage($state, $target, 5, true);
        $events = [...$events, ...$damageEvents];

        foreach ($allies as $role) {
            [$state, $damageEvents] = self::applyDamage($state, $role, 5, true);
            $events = [...$events, ...$damageEvents];
        }

        return [$state, $events];
    }

    /**
     * Whirlwind: "Pick up all infrastructure tokens. Shuffle them, and
     * redistribute them at random. Ensure no space has two of the same
     * token as usual." Flattens every currently-placed token (any mix
     * of monolith_shard/fruit_tree/walls) off the whole board, shuffles
     * both the tokens and the candidate cells, then deals tokens back
     * out one at a time — skipping a cell that would end up with two
     * of the same type, same uniqueness Build/Divine aura already
     * enforce. A token that can't legally land anywhere (more of one
     * type than there are eligible cells left) returns to
     * infrastructurePool rather than vanishing.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemWhirlwind(GameState $state): array
    {
        $tokens = [];
        $board = $state->board;

        foreach ($board as $key => $entry) {
            foreach ($entry['infrastructure'] as $type) {
                $tokens[] = $type;
            }

            $board[$key] = [...$entry, 'infrastructure' => []];
        }

        if ($tokens === []) {
            return [$state, []];
        }

        shuffle($tokens);
        $cellKeys = array_keys($board);
        shuffle($cellKeys);

        $pool = $state->infrastructurePool;

        foreach ($tokens as $type) {
            $placed = false;

            foreach ($cellKeys as $key) {
                if (! in_array($type, $board[$key]['infrastructure'], true)) {
                    $board[$key] = [...$board[$key], 'infrastructure' => [...$board[$key]['infrastructure'], $type]];
                    $placed = true;
                    break;
                }
            }

            if (! $placed) {
                $pool[$type] = ($pool[$type] ?? 0) + 1;
            }
        }

        $state = $state->with(['board' => $board, 'infrastructurePool' => $pool]);

        return [$state, [['type' => 'infrastructure_redistributed', 'role_key' => null, 'payload' => []]]];
    }

    /**
     * Dark swell: "All Wanderers on spaces that produce 🌑 or 🔥 lose
     * 5HP and gain a Burn token."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemDarkSwell(GameState $state): array
    {
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $location = LocationCatalog::find($state->board[Board::key($cell)]['location_key']);

            if (! in_array($location?->producesEmoji, ['🌑', '🔥'], true)) {
                continue;
            }

            [$state, $damageEvents] = self::applyDamage($state, $role, 5, true);
            $events = [...$events, ...$damageEvents];

            [$state, $burnEvents] = self::applyBurn($state, $role);
            $events = [...$events, ...$burnEvents];
        }

        return [$state, $events];
    }

    /**
     * Health inversion: "All Wanderers on spaces that produce ⏳ change
     * their HP to X, where X = (15 - their current HP)." A direct HP
     * set (like Cabalist's Gown's own "set to 5"), not routed through
     * applyDamage — there's no 👊 wording here, so Golem-attack-scoped
     * Glyphs/Relics (Cruel, Dark sacrifice, etc.) don't apply.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemHealthInversion(GameState $state): array
    {
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $location = LocationCatalog::find($state->board[Board::key($cell)]['location_key']);

            if ($location?->producesEmoji !== '⏳') {
                continue;
            }

            $oldHp = $state->wanderers[$role]['hp'];
            $newHp = max(0, min(self::maxHpFor($state, $role), 15 - $oldHp));

            if ($newHp === $oldHp) {
                continue;
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $newHp])]);

            if ($newHp > $oldHp) {
                $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $newHp]];
            } else {
                $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => $oldHp - $newHp]];

                if ($newHp === 0) {
                    $state = $state->with(['wanderers' => $state->wanderersWith($role, ['was_ever_exhausted' => true])]);
                }
            }
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemAttackHere(GameState $state, int $damage): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        return self::damageWanderersAt($state, [$golemCell], $damage, true);
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemDamageWithinDistance(GameState $state, int $distance, int $damage): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $cells = Board::cellsWithinDistance($golemCell, $distance, $state->board);

        return self::damageWanderersAt($state, $cells, $damage, true);
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemDamageAllWanderers(GameState $state, int $damage): array
    {
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            [$state, $damageEvents] = self::applyDamage($state, $role, $damage, true);
            $events = [...$events, ...$damageEvents];
        }

        return [$state, $events];
    }

    /**
     * Dissent: each Wanderer sharing a space with another Wanderer loses
     * Health.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemDamageSharedCells(GameState $state, int $damage): array
    {
        $byCell = [];
        foreach (self::activeWanderers($state) as $role => $cell) {
            $byCell[Board::key($cell)][] = $role;
        }

        $events = [];
        foreach ($byCell as $roles) {
            if (count($roles) < 2) {
                continue;
            }

            foreach ($roles as $role) {
                [$state, $damageEvents] = self::applyDamage($state, $role, $damage, true);
                $events = [...$events, ...$damageEvents];
            }
        }

        return [$state, $events];
    }

    /**
     * Death's Door: every Wanderer on or adjacent to the Golem's space
     * goes to exactly the given HP.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemSetHpOnAndAdjacent(GameState $state, int $hp): array
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return [$state, []];
        }

        $affectedKeys = [
            Board::key($golemCell),
            ...array_map(Board::key(...), Board::occupiedNeighbors($golemCell, $state->board)),
        ];

        $events = [];
        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $affectedKeys, true)) {
                continue;
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_hp_set', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $cells
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function damageWanderersAt(GameState $state, array $cells, int $damage, bool $isGolemAttack = false): array
    {
        $cellKeys = array_map(Board::key(...), $cells);
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            [$state, $damageEvents] = self::applyDamage($state, $role, $damage, $isGolemAttack);
            $events = [...$events, ...$damageEvents];
        }

        return [$state, $events];
    }

    /**
     * Applies damage, honoring Dark sacrifice's "reduce incoming
     * damage by 1 per equipped Dark Relic" and Cabalist wraps' "never
     * become exhausted — stay at 1, Allies nearby lose 5, Golem taps
     * 2" save. $isGolemAttack additionally triggers every passive
     * Relic keyed off "when/after the Golem 👊 you" specifically (not
     * damage from any other source, like a Wanderer's own Relic).
     * Emits its own 'wanderer_damaged' event using the actual Health
     * lost (not the raw $damage parameter — see the comment at its
     * construction below) unless the attack turned into a heal instead
     * (Inverted Glyph) or was fully absorbed before any Health math
     * happens (Cabalist's Gown). Callers merge the returned events
     * directly; they should not construct their own 'wanderer_damaged'
     * event for this call.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyDamage(GameState $state, string $role, int $damage, bool $isGolemAttack = false): array
    {
        $equipped = $state->wanderers[$role]['equipped'];

        // Cabalist's Gown: "While you are on a space that produces 🌑,
        // your Health never decreases."
        $cell = $state->wanderers[$role]['cell'];
        if ($cell !== null && in_array('cabalists-gown', $equipped, true)) {
            $location = LocationCatalog::find($state->board[Board::key($cell)]['location_key']);

            if ($location?->producesEmoji === '🌑') {
                return [$state, []];
            }
        }

        // Inverted Glyph: "When the Golem 👊 on a space that produces
        // ⏳, it increases Wanderer Health instead of decreasing it."
        // Checked before any other incoming-damage logic — on a
        // matching attack the Golem heals instead of hurting, full
        // stop, so none of the damage-reduction/reaction logic below
        // (Dark Relics, Poison Charm, Cabalist wraps, etc.) applies.
        if ($isGolemAttack && $cell !== null && in_array('inverted', $state->golem['glyphs'], true)) {
            $location = LocationCatalog::find($state->board[Board::key($cell)]['location_key']);

            if ($location?->producesEmoji === '⏳') {
                $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + $damage);
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);

                return [$state, [['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]]]];
            }
        }

        $events = [];
        $rawDamage = $damage;
        $modifiers = [];

        // Golem insight: "Once per round, when the Golem attacks you,
        // you may 🦶1 before the attack." Always taken — healing before
        // damage is strictly better, so there's no real decision to model.
        if ($isGolemAttack && in_array('golem-insight', $equipped, true) && ! $state->wanderers[$role]['golem_insight_used_this_round']) {
            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 1);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, [
                'hp' => $hp,
                'golem_insight_used_this_round' => true,
            ])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        // Ephemeral protection: "If you are on a space with a Monolith
        // Shard and the Golem attacks you: you may choose lose 0 Health,
        // trash this, trash the Monolith shard, and gain 📜." Always
        // taken — strictly better than taking the hit.
        if ($isGolemAttack && in_array('ephemeral-protection', $equipped, true) && $cell !== null && self::hasMonolithShardFor($state, $role, $cell)) {
            $state = self::trashEquippedRelic($state, $role, 'ephemeral-protection');
            $events[] = ['type' => 'relic_trashed', 'role_key' => $role, 'payload' => ['relic_key' => 'ephemeral-protection']];

            $cellKey = Board::key($cell);
            $infrastructure = $state->board[$cellKey]['infrastructure'];
            $shardIndex = array_search('monolith_shard', $infrastructure, true);
            if ($shardIndex !== false) {
                unset($infrastructure[$shardIndex]);
                $board = $state->board;
                $board[$cellKey] = [...$board[$cellKey], 'infrastructure' => array_values($infrastructure)];
                $state = $state->with(['board' => $board, 'infrastructurePool' => [
                    ...$state->infrastructurePool,
                    'monolith_shard' => $state->infrastructurePool['monolith_shard'] + 1,
                ]]);
                $events[] = ['type' => 'infrastructure_removed', 'role_key' => null, 'payload' => ['cell' => $cell, 'infrastructure_type' => 'monolith_shard']];
            }

            $state = $state->with(['prophecy' => $state->prophecy + 1]);
            $events[] = ['type' => 'prophecy_gained', 'role_key' => $role, 'payload' => ['amount' => 1]];

            return [$state, $events];
        }

        // Divine sack cloth of sacrifice: "When Allies lose Health, you
        // may always incur half of the damage on their behalf." Always
        // taken on the Ally's behalf (there's no downside to the Ally),
        // and only the first holder found redirects — avoids chaining
        // through multiple holders at once.
        foreach (self::activeWanderers($state) as $otherRole => $otherCell) {
            if ($otherRole === $role || ! in_array('divine-sack-cloth-of-sacrifice', $state->wanderers[$otherRole]['equipped'], true)) {
                continue;
            }

            $sacrificed = intdiv($damage, 2);
            $damage -= $sacrificed;

            if ($sacrificed > 0) {
                $holderHp = max(0, $state->wanderers[$otherRole]['hp'] - $sacrificed);
                $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['hp' => $holderHp])]);
                $events[] = ['type' => 'wanderer_damaged', 'role_key' => $otherRole, 'payload' => ['amount' => $sacrificed]];
                $modifiers[] = "Divine sack cloth of sacrifice (-{$sacrificed}, redirected)";

                if ($holderHp === 0) {
                    $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['was_ever_exhausted' => true])]);
                }
            }

            break;
        }

        // Dark sacrifice: "For each Relic you have equipped with 'Dark
        // ...' in its name, reduce incoming damage by 1." The ability
        // belongs to Dark sacrifice itself — merely having OTHER Dark
        // Relics equipped (Dark shackles/limitations/sigil) without Dark
        // sacrifice grants no reduction, same bug shape as Emperor's
        // Knowledge applying its bonus without Emperor's Knowledge
        // itself equipped.
        $darkCount = 0;
        if (in_array('dark-sacrifice', $equipped, true)) {
            $darkCount = count(array_filter(
                $equipped,
                fn (mixed $key) => is_string($key) && str_starts_with((string) RelicCatalog::find($key)?->name, 'Dark')
            ));
        }
        if ($darkCount > 0) {
            $modifiers[] = "Dark sacrifice (-{$darkCount})";
        }
        $damage = max(0, $damage - $darkCount);

        // Garbage barricade: "When the Golem attacks Wanderers in this
        // space, reduce damage by 1 for every 2 Relics tucked here."
        if ($isGolemAttack && $cell !== null && $state->board[Board::key($cell)]['location_key'] === 'garbage-barricade') {
            $barricadeReduction = intdiv(count($state->board[Board::key($cell)]['tucked']), 2);

            if ($barricadeReduction > 0) {
                $modifiers[] = "Garbage barricade (-{$barricadeReduction})";
            }
            $damage = max(0, $damage - $barricadeReduction);
        }

        // Combat Glyphs — all five only ever modify a Golem attack's
        // damage, never damage from any other source.
        if ($isGolemAttack) {
            $golemGlyphs = $state->golem['glyphs'];

            // Cruel: "+1 for all 👊."
            if (in_array('cruel', $golemGlyphs, true)) {
                $damage += 1;
                $modifiers[] = 'Cruel (+1)';
            }

            // Legalistic: "+1👊 for each Shame the Wanderer has" — the
            // Wanderer being hit, not the Golem's own Shame.
            if (in_array('legalistic', $golemGlyphs, true)) {
                $legalisticBonus = $state->wanderers[$role]['shame'];
                $damage += $legalisticBonus;
                if ($legalisticBonus > 0) {
                    $modifiers[] = "Legalistic (+{$legalisticBonus})";
                }
            }

            // Merciful: "-1👊."
            if (in_array('merciful', $golemGlyphs, true)) {
                $damage = max(0, $damage - 1);
                $modifiers[] = 'Merciful (-1)';
            }

            // Enchanted: "-2👊 if the Golem is on a space with a
            // Monolith Shard" — the Golem's own space, not the
            // Wanderer's.
            $golemCell = $state->golem['cell'];
            if (in_array('enchanted', $golemGlyphs, true) && $golemCell !== null
                && in_array('monolith_shard', $state->board[Board::key($golemCell)]['infrastructure'], true)) {
                $damage = max(0, $damage - 2);
                $modifiers[] = 'Enchanted (-2)';
            }

            // Outnumbered: "-3👊 when attacking Wanderers who are in a
            // space with other Wanderers."
            if (in_array('outnumbered', $golemGlyphs, true) && $cell !== null) {
                foreach (self::activeWanderers($state) as $otherRole => $otherCell) {
                    if ($otherRole !== $role && Board::key($otherCell) === Board::key($cell)) {
                        $damage = max(0, $damage - 3);
                        $modifiers[] = 'Outnumbered (-3)';
                        break;
                    }
                }
            }
        }

        // Poison Charm: the Golem's current attack card is -3, for
        // everyone it hits.
        if ($isGolemAttack && $state->golem['active_attack_reduction'] > 0) {
            $damage = max(0, $damage - $state->golem['active_attack_reduction']);
            $modifiers[] = "Poison Charm (-{$state->golem['active_attack_reduction']})";
        }

        $hpBefore = $state->wanderers[$role]['hp'];
        $hp = max(0, $hpBefore - $damage);

        // Accusing Glyph: "When the Golem plays a card with 👊 that
        // causes a Wanderer to lose health, the Wanderer gains 1
        // Shame." Checked against the actual Health loss, not the
        // raw $damage figure — a fully-absorbed hit (Cabalist's Gown,
        // Dark Relics reducing it to 0) doesn't cause any.
        if ($isGolemAttack && $hp < $hpBefore && in_array('accusing', $state->golem['glyphs'], true)) {
            [$state, $shameEvents] = self::applyShame($state, $role);
            $events = [...$events, ...$shameEvents];
        }

        // The actual Health lost, not the raw $damage parameter — every
        // caller used to log the raw figure regardless of any reduction
        // applied above, so e.g. a hit reduced from 2 to 1 by Dark
        // sacrifice was reported in the activity log/Golem Turn
        // Spotlight as "lost 2 Health" even though the real loss was 1.
        // Built once here (instead of each of applyDamage()'s ~15
        // callers re-deriving it) and inserted as the first event of
        // whichever branch runs below, so it stays ahead of any
        // follow-up event that branch adds (e.g. Miraculous's heal).
        $finalHp = ($hp <= 0 && $hpBefore > 0 && in_array('cabalist-wraps', $equipped, true)) ? 1 : $hp;
        $damagePayload = ['amount' => $hpBefore - $finalHp];
        if ($modifiers !== []) {
            $damagePayload['raw_amount'] = $rawDamage;
            $damagePayload['modifiers'] = $modifiers;
        }
        $damagedEvent = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => $damagePayload];

        if ($hp <= 0 && $hpBefore > 0 && in_array('cabalist-wraps', $equipped, true)) {
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => 1])]);
            $events[] = $damagedEvent;
            $events[] = ['type' => 'cabalist_wraps_triggered', 'role_key' => $role, 'payload' => []];

            $cell = $state->wanderers[$role]['cell'];
            $nearbyKeys = $cell === null
                ? []
                : [Board::key($cell), ...array_map(Board::key(...), Board::occupiedNeighbors($cell, $state->board))];

            foreach (self::activeWanderers($state) as $otherRole => $otherCell) {
                if ($otherRole === $role || ! in_array(Board::key($otherCell), $nearbyKeys, true)) {
                    continue;
                }

                $otherHp = max(0, $state->wanderers[$otherRole]['hp'] - 5);
                $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['hp' => $otherHp])]);
                $events[] = ['type' => 'wanderer_damaged', 'role_key' => $otherRole, 'payload' => ['amount' => 5]];
            }

            [$state, $tapEvents] = self::tapGolemDrawPile($state, 2);
            $events = [...$events, ...$tapEvents];
        } else {
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = $damagedEvent;

            // Catacombs: "A Wanderer can only enter this space if they
            // were exhausted earlier in the game." Cabalist wraps
            // explicitly prevents "becoming exhausted," so that branch
            // doesn't set this.
            if ($hp === 0) {
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['was_ever_exhausted' => true])]);

                // Miraculous Glyph: "If any Wanderer becomes exhausted,
                // they immediately 🌿3." Only the actual transition
                // into exhaustion ($hpBefore > 0) counts, not further
                // damage piling onto an already-exhausted Wanderer.
                if ($hpBefore > 0 && in_array('miraculous', $state->golem['glyphs'], true)) {
                    $healedHp = min(self::maxHpFor($state, $role), 3);
                    $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $healedHp])]);
                    $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $healedHp]];
                }
            }
        }

        if ($isGolemAttack) {
            [$state, $reactiveEvents] = self::applyGolemAttackReactions($state, $role);
            $events = [...$events, ...$reactiveEvents];
        }

        return [$state, $events];
    }

    /**
     * Every passive Relic keyed off "when/after the Golem 👊 you":
     * Poison Blouse (tap its next draw pile card), Divine Poison
     * Inversion (heal 2, or 4 if the holder's space produces 📖),
     * inscribe-7 (add a 🌑 Glyph, then trash), and
     * Buckler (immediately use the Action in your other 🖐️ Hand for
     * free — no action cost, no target_role, since there's no
     * decision UI to supply either).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyGolemAttackReactions(GameState $state, string $role): array
    {
        $equipped = $state->wanderers[$role]['equipped'];
        $events = [];

        if (in_array('poison-blouse', $equipped, true)) {
            [$state, $e] = self::tapGolemDrawPile($state, 1);
            $events = [...$events, ...$e];
        }

        if (in_array('divine-poison-inversion', $equipped, true)) {
            $cell = $state->wanderers[$role]['cell'];
            $location = $cell === null ? null : LocationCatalog::find($state->board[Board::key($cell)]['location_key']);
            $amount = $location?->producesEmoji === '📖' ? 4 : 2;

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + $amount);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        // Poison Charm: "After the Golem 👊 you, the next 👊 card it
        // plays this round is -3." Queued here, not applied immediately
        // — promoted to active_attack_reduction when the next attack
        // card actually starts resolving (see resolveOrSkipTappedCard),
        // so it reduces that whole card's damage rather than this one's
        // (which already finished applying its own damage by this point).
        if (in_array('poison-charm', $equipped, true)) {
            $state = $state->with(['golem' => $state->golemWith(['armed_attack_reduction' => 3])]);
        }

        if (in_array('inscribe-7', $equipped, true)) {
            [$state, $e] = self::addGlyphOfType($state, '🌑', $role, 1);
            $events = [...$events, ...$e];

            if ($e !== []) {
                $state = self::trashEquippedRelic($state, $role, 'inscribe-7');
                $events[] = ['type' => 'relic_trashed', 'role_key' => $role, 'payload' => ['relic_key' => 'inscribe-7']];
            }
        }

        $bucklerSlot = array_search('buckler', $equipped, true);

        if ($bucklerSlot === 'hand_left' || $bucklerSlot === 'hand_right') {
            $otherHandSlot = $bucklerSlot === 'hand_left' ? 'hand_right' : 'hand_left';
            $otherRelicKey = $equipped[$otherHandSlot];

            if ($otherRelicKey !== null && ! self::isStatusToken($otherRelicKey)) {
                $otherBaseKey = (string) preg_replace('/-\d+$/', '', $otherRelicKey);
                [$state, $innerEvents, $innerTrash] = self::resolveRelicAction($state, $role, $otherBaseKey, $otherRelicKey, []);
                $events = [...$events, ...$innerEvents];

                if ($innerTrash) {
                    $state = self::trashEquippedRelic($state, $role, $otherRelicKey);
                    $events[] = ['type' => 'relic_trashed', 'role_key' => $role, 'payload' => ['relic_key' => $otherRelicKey]];
                }
            }
        }

        return [$state, $events];
    }

    /**
     * @return array<string, array{0: int, 1: int}> active (non-exhausted) Wanderers' cells, keyed by role
     */
    private static function activeWanderers(GameState $state): array
    {
        $result = [];

        foreach ($state->wanderers as $role => $wanderer) {
            if ($wanderer['cell'] !== null && $wanderer['hp'] > 0) {
                $result[$role] = $wanderer['cell'];
            }
        }

        return $result;
    }

    private static function nearestWanderer(GameState $state): ?string
    {
        return self::extremeWanderer($state, fn (int $a, int $b) => $a < $b);
    }

    private static function farthestWanderer(GameState $state): ?string
    {
        return self::extremeWanderer($state, fn (int $a, int $b) => $a > $b);
    }

    /**
     * Invisibility Cloak: "If there are 5+ Monolith Shards on or
     * adjacent to your space, the Golem cannot target you." Only ever
     * excludes a Wanderer from single-target selection (nearest/
     * farthest/least-HP/most-HP/most-Shame — every function below that
     * reads this instead of activeWanderers()) — same scope as Focused
     * Glyph's own override; area-effect cards still hit them.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private static function targetableWanderers(GameState $state): array
    {
        return array_filter(
            self::activeWanderers($state),
            fn (array $cell, string $role) => ! self::isInvisibleToGolem($state, $role),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function isInvisibleToGolem(GameState $state, string $role): bool
    {
        if (! in_array('invisibility-cloak', $state->wanderers[$role]['equipped'], true)) {
            return false;
        }

        $cell = $state->wanderers[$role]['cell'];

        if ($cell === null) {
            return false;
        }

        return self::monolithShardsWithin($state, $cell, 1) >= 5;
    }

    private static function extremeWanderer(GameState $state, \Closure $isBetter): ?string
    {
        // Focused Glyph: "The Golem can only ever target the Wanderer
        // with the most Health. All other Wanderers are effectively
        // invisible to it." Overrides nearest/farthest targeting
        // outright — every card that would otherwise pick by distance
        // picks by Health instead.
        if (in_array('focused', $state->golem['glyphs'], true)) {
            return self::mostHpWanderer($state);
        }

        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return null;
        }

        $best = null;
        $bestDistance = null;

        foreach (self::targetableWanderers($state) as $role => $cell) {
            $distance = Board::distance($golemCell, $cell, $state->board, $state->wallKeys());

            if ($distance === null) {
                continue;
            }

            if ($bestDistance === null || $isBetter($distance, $bestDistance)) {
                $best = $role;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private static function mostShameWanderer(GameState $state): ?string
    {
        $best = null;
        $bestShame = -1;

        foreach (self::targetableWanderers($state) as $role => $cell) {
            $shame = $state->wanderers[$role]['shame'];

            if ($shame > $bestShame) {
                $best = $role;
                $bestShame = $shame;
            }
        }

        return $best;
    }

    private static function golemSharesCellWith(GameState $state, string $role): bool
    {
        $golemCell = $state->golem['cell'];
        $wandererCell = $state->wanderers[$role]['cell'];

        return $golemCell !== null && $wandererCell !== null && Board::key($golemCell) === Board::key($wandererCell);
    }

    private static function leastHpWanderer(GameState $state): ?string
    {
        if (in_array('focused', $state->golem['glyphs'], true)) {
            return self::mostHpWanderer($state);
        }

        $best = null;
        $bestHp = null;

        foreach (self::targetableWanderers($state) as $role => $cell) {
            $hp = $state->wanderers[$role]['hp'];

            if ($bestHp === null || $hp < $bestHp) {
                $best = $role;
                $bestHp = $hp;
            }
        }

        return $best;
    }

    private static function mostHpWanderer(GameState $state): ?string
    {
        $best = null;
        $bestHp = null;

        foreach (self::targetableWanderers($state) as $role => $cell) {
            $hp = $state->wanderers[$role]['hp'];

            if ($bestHp === null || $hp > $bestHp) {
                $best = $role;
                $bestHp = $hp;
            }
        }

        return $best;
    }

    /**
     * $prophecyOnSuccess: every "Inscribe"-family Relic (and Pray,
     * Aural inscription, Jericho inscription) grants 📜 — 1 for most,
     * 3 for Inscribe #4 — in the same breath as adding the Glyph, but
     * ONLY if a Glyph of that type was actually left in the deck to
     * add (confirmed in production, game WNNDOZ: every one of these
     * granted its Glyph correctly but never granted the Prophecy its
     * own printed text promised, since this was the one shared
     * function behind all of them and never granted it at all).
     * Callers that add a Glyph without their own Prophecy clause
     * (Rift in time, the Golem's own self-inscription card) simply
     * omit this and keep the default of 0.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function addGlyphOfType(GameState $state, string $type, ?string $actorRole = null, int $prophecyOnSuccess = 0): array
    {
        $deck = $state->glyphDeck;
        $index = null;

        foreach ($deck as $i => $glyphKey) {
            if (GlyphCatalog::find($glyphKey)?->type === $type) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return [$state, []];
        }

        $glyphKey = $deck[$index];
        unset($deck[$index]);
        $deck = array_values($deck);

        $glyphs = $state->golem['glyphs'];
        array_unshift($glyphs, $glyphKey);
        $discardedGlyph = null;

        if (count($glyphs) > self::GOLEM_MAX_GLYPHS) {
            $discardedGlyph = array_pop($glyphs);
        }

        $glyphDiscardPile = $state->glyphDiscardPile;

        if ($discardedGlyph !== null) {
            $glyphDiscardPile[] = $discardedGlyph;
        }

        $state = $state->with([
            'golem' => $state->golemWith(['glyphs' => $glyphs]),
            'glyphDeck' => $deck,
            'glyphDiscardPile' => $glyphDiscardPile,
        ]);

        $events = [['type' => 'glyph_added', 'role_key' => null, 'payload' => ['glyph_key' => $glyphKey]]];

        if ($discardedGlyph !== null) {
            $events[] = ['type' => 'glyph_discarded', 'role_key' => null, 'payload' => ['glyph_key' => $discardedGlyph]];
        }

        // Enraged: "Untap any tapped Golem Cards." Fires once, right as
        // Enraged itself becomes active — not an ongoing re-check, so
        // this only needs to run when $glyphKey === 'enraged', not on
        // every subsequent addGlyphOfType call while it's still active
        // (tapGolemDrawPile's own gate already prevents anything from
        // becoming tapped again in the meantime).
        if ($glyphKey === 'enraged') {
            $golem = $state->golem;

            foreach ($golem['draw_pile'] as $i => $entry) {
                $golem['draw_pile'][$i]['tapped'] = false;
            }

            foreach ($golem['revealed'] as $i => $entry) {
                $golem['revealed'][$i]['tapped'] = false;
            }

            $state = $state->with(['golem' => $golem]);
            $events[] = ['type' => 'golem_cards_untapped', 'role_key' => null, 'payload' => []];
        }

        // Prohibition of inscribing: "If anyone (Wanderer or Golem) adds
        // a Glyph to the Golem mat, they gain 1 Shame token (max 3)."
        // Gated on the card being equipped ANYWHERE (it describes a
        // standing rule while it's in play, not a personal reaction).
        // A Wanderer-attributed add gains a Shame status token; the
        // Golem's own card effects (self-inscription) pass no
        // actorRole, but the Golem has its own Shame counter
        // (golem['shame'], capped at 3 the same as everywhere else it's
        // gained) and gains that instead — confirmed in production:
        // equipped, then the Golem added "Vindictive" via a card with
        // no actorRole, and gained no Shame at all.
        $prohibitionInPlay = false;
        foreach ($state->wanderers as $otherWanderer) {
            if (in_array('prohibition-of-inscribing', $otherWanderer['equipped'], true)) {
                $prohibitionInPlay = true;
                break;
            }
        }

        if ($prohibitionInPlay) {
            if ($actorRole !== null) {
                [$state, $shameEvents] = self::applyShame($state, $actorRole);
                $events = [...$events, ...$shameEvents];
            } elseif ($state->golem['shame'] < 3) {
                $state = $state->with(['golem' => $state->golemWith(['shame' => $state->golem['shame'] + 1])]);
                $events[] = ['type' => 'golem_shame_gained', 'role_key' => null, 'payload' => ['amount' => 1]];
            }
        }

        if ($actorRole !== null && $prophecyOnSuccess > 0) {
            $state = $state->with(['prophecy' => $state->prophecy + $prophecyOnSuccess]);
            $events[] = ['type' => 'prophecy_gained', 'role_key' => $actorRole, 'payload' => ['amount' => $prophecyOnSuccess]];
        }

        return [$state, $events];
    }

    /**
     * Whether a neighbor of $cell produces $branch — either directly, or
     * (confirmed via chat 2026-11-12, not yet in rules.md — see
     * rules-todo.md) because that neighbor has a Road connecting it to a
     * network of other Road spaces, any of which produces it.
     *
     * @param  array{0: int, 1: int}  $cell
     */
    private static function effectiveProduces(GameState $state, array $cell, string $branch): bool
    {
        foreach (Board::occupiedNeighbors($cell, $state->board) as $neighbor) {
            $entry = $state->board[Board::key($neighbor)];
            $location = LocationCatalog::find($entry['location_key']);

            if ($location?->produces === $branch) {
                return true;
            }

            // Library: "This location produces every resource that the
            // city already produces."
            if ($entry['location_key'] === 'library' && self::cityProducesAnywhere($state, $branch)) {
                return true;
            }

            // Earthquake Glyph: "Roads and walls have no effect" —
            // Road's own network-production extension is one of
            // those effects.
            if (in_array('road', $entry['infrastructure'], true)
                && ! in_array('earthquake', $state->golem['glyphs'], true)
                && in_array($branch, self::roadNetworkProduces($state, $neighbor), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether any built Location anywhere on the board produces
     * $branch — Library's own dynamic production.
     */
    private static function cityProducesAnywhere(GameState $state, string $branch): bool
    {
        foreach ($state->board as $entry) {
            if (LocationCatalog::find($entry['location_key'])?->produces === $branch) {
                return true;
            }
        }

        return false;
    }

    /**
     * BFS over the contiguous network of Road-having spaces starting at
     * $startCell (which must itself have a Road), collecting every
     * member's own produced resource.
     *
     * @param  array{0: int, 1: int}  $startCell
     * @return list<string>
     */
    private static function roadNetworkProduces(GameState $state, array $startCell): array
    {
        $visited = [Board::key($startCell) => true];
        $queue = [$startCell];
        $produces = [];

        while ($queue !== []) {
            $cell = array_shift($queue);
            $entry = $state->board[Board::key($cell)];
            $location = LocationCatalog::find($entry['location_key']);

            if ($location?->produces !== null) {
                $produces[] = $location->produces;
            }

            foreach (Board::occupiedNeighbors($cell, $state->board) as $neighbor) {
                $neighborKey = Board::key($neighbor);

                if (isset($visited[$neighborKey])) {
                    continue;
                }

                if (! in_array('road', $state->board[$neighborKey]['infrastructure'], true)) {
                    continue;
                }

                $visited[$neighborKey] = true;
                $queue[] = $neighbor;
            }
        }

        return $produces;
    }

    private static function requireRole(GameState $state, string $actorRole, ?string $expectedRole, string $expectedPhase): void
    {
        if ($state->phase !== $expectedPhase) {
            throw new InvalidMoveException("It isn't the {$expectedPhase} phase.");
        }

        if ($actorRole !== $expectedRole) {
            throw new InvalidMoveException("It isn't your turn.");
        }
    }

    /**
     * rules.md: "if you are at 0 HP, [resting] is the only action you
     * can perform" — Move/Search/Use-relic are blocked at 0 HP, but
     * resting itself, and the always-free equip/drop/pickup actions,
     * are not.
     *
     * @param  array<string, mixed>  $wanderer
     */
    private static function requireNotExhausted(array $wanderer): void
    {
        if ($wanderer['hp'] <= 0) {
            throw new InvalidMoveException('At 0 Health, resting is the only action you can take.');
        }
    }

    /**
     * rules.md: "🌿: increase Health by 1" — the baseline Wanderer
     * action available even at 0 HP (where it's the only one
     * available). Reduced from 2 (designer's own justification, pulled
     * via canticle:pull-changes, rulebook revision #3): "reduce self
     * healing to 1, which makes exhaustion even more punishing."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function rest(GameState $state, string $actorRole): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];

        if ($wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        $hp = min(self::maxHpFor($state, $actorRole), $wanderer['hp'] + 1);
        $wanderers = $state->wanderersWith($actorRole, [
            'hp' => $hp,
            'actions_remaining' => $wanderer['actions_remaining'] - 1,
        ]);
        $state = $state->with(['wanderers' => $wanderers]);

        return [$state, [['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]]]];
    }

    /**
     * Smoldering Glyph: "On their turn, Wanderers may trash Relics
     * (not including Bound Relics) from their mat, and gain 2 Health
     * for each they trash. This does not cost an action." A new move
     * type rather than an auto-resolved reaction — unlike every other
     * Glyph effect so far, this is a genuine optional choice of WHICH
     * (if any) equipped Relics to give up, with no single "always
     * correct" answer.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function trashRelicForHealth(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        if (! in_array('smoldering', $state->golem['glyphs'], true)) {
            throw new InvalidMoveException('Smoldering is not active.');
        }

        $relicKeys = $move['relic_keys'] ?? [];

        if (! is_array($relicKeys) || $relicKeys === []) {
            throw new InvalidMoveException('At least one Relic key is required.');
        }

        $events = [];
        $trashedCount = 0;

        foreach ($relicKeys as $relicKey) {
            if (! is_string($relicKey) || self::isStatusToken($relicKey)) {
                continue;
            }

            if (! in_array($relicKey, $state->wanderers[$actorRole]['equipped'], true)) {
                continue;
            }

            if (RelicCatalog::find($relicKey)?->isBound === true) {
                continue;
            }

            $state = self::trashEquippedRelic($state, $actorRole, $relicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]];
            $trashedCount++;
        }

        if ($trashedCount > 0) {
            $hp = min(self::maxHpFor($state, $actorRole), $state->wanderers[$actorRole]['hp'] + 2 * $trashedCount);
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Neighborly Glyph: "Any time a Wanderer loses Health, one of
     * their Allies may move to their space and immediately use a
     * free 🌿 Relic action from one of their own Relics." A genuine
     * optional choice of whether to help, who to send, and which of
     * their own Relics to spend — a new move type, same as
     * Smoldering, rather than an auto-resolved reaction.
     *
     * Simplification: usable any time (not strictly windowed to
     * "immediately after" an Ally's Health loss) — there's no clean
     * seam yet for a reaction that must happen in the instant between
     * one move and the next, same documented gap as Laborer's
     * Jacket's "start of the Wanderer phase" timing elsewhere in this
     * engine. Players self-police using it right after the fact.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function neighborlyAssist(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        if (! in_array('neighborly', $state->golem['glyphs'], true)) {
            throw new InvalidMoveException('Neighborly is not active.');
        }

        $toRole = (string) ($move['to_role'] ?? '');
        $ally = $state->wanderers[$toRole] ?? null;

        if ($toRole === $actorRole || $ally === null || $ally['cell'] === null) {
            throw new InvalidMoveException('That Ally is not on the board.');
        }

        $actorCell = $state->wanderers[$actorRole]['cell'];

        if (! in_array(Board::key($ally['cell']), array_map(Board::key(...), Board::occupiedNeighbors($actorCell, $state->board)), true)) {
            throw new InvalidMoveException('That Ally is not adjacent to you.');
        }

        $relicKey = (string) ($move['relic_key'] ?? '');
        $relic = RelicCatalog::find($relicKey);

        if ($relic === null || ! str_contains($relic->effect, '🌿') || ! in_array($relicKey, $state->wanderers[$actorRole]['equipped'], true)) {
            throw new InvalidMoveException('That is not one of your own 🌿 Relics.');
        }

        if (self::entryBlocked($state, $actorRole, $ally['cell'])) {
            throw new InvalidMoveException('Catacombs: only a Wanderer who has been exhausted before may enter.');
        }

        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['cell' => $ally['cell']])]);
        $events = [['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $ally['cell'], 'free' => true]]];

        [$state, $e] = self::moveDarkShacklesGolem($state, $actorRole, $actorCell, $ally['cell']);
        $events = [...$events, ...$e];

        // Default the Relic's own target to the Ally being helped, not
        // the helper — the whole point of coming to their rescue.
        // Relics that heal everyone sharing the caster's space (e.g.
        // Burial robes) ignore target_role anyway and heal both, now
        // that they share a space.
        $baseKey = (string) preg_replace('/-\d+$/', '', $relicKey);
        [$state, $relicEvents, $trash] = self::resolveRelicAction($state, $actorRole, $baseKey, $relicKey, [...$move, 'target_role' => $toRole]);
        $events = [...$events, ...$relicEvents];

        if ($trash) {
            $state = self::trashEquippedRelic($state, $actorRole, $relicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]];
        }

        return [$state, $events];
    }

    /**
     * Perplexing Glyph: "When the Emperor plays a Location, Wanderers
     * may choose to immediately move there at no cost." Not gated to
     * the mover's own turn — the opportunity arises during the
     * Emperor's phase, before any Wanderer turn exists yet, so (like
     * equip/drop) this is always available rather than tied to
     * actions_remaining.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function moveToNewLocation(GameState $state, string $actorRole): array
    {
        self::requireWandererWithCell($state, $actorRole);

        if (! in_array('perplexing', $state->golem['glyphs'], true)) {
            throw new InvalidMoveException('Perplexing is not active.');
        }

        $target = $state->locationJustBuiltCell;

        if ($target === null) {
            throw new InvalidMoveException('No Location has been played yet.');
        }

        if (Board::key($state->wanderers[$actorRole]['cell']) === Board::key($target)) {
            throw new InvalidMoveException('You are already there.');
        }

        if (self::entryBlocked($state, $actorRole, $target)) {
            throw new InvalidMoveException('Catacombs: only a Wanderer who has been exhausted before may enter.');
        }

        $fromCell = $state->wanderers[$actorRole]['cell'];
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['cell' => $target])]);
        $events = [['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $target, 'free' => true]]];

        [$state, $e] = self::moveDarkShacklesGolem($state, $actorRole, $fromCell, $target);
        $events = [...$events, ...$e];

        return [$state, $events];
    }

    /**
     * Parallel Glyph: "Once per round, Wanderers may choose to skip a
     * drawn Golem card and draw the next card instead." The card
     * about to resolve is whichever one is at the end of 'revealed'
     * (resolution reads right-to-left, array_pop takes the last
     * element) — discarding it unresolved and refilling from the draw
     * pile is exactly "skip it, draw the next one instead." Shared
     * across all Wanderers (not tracked per-Wanderer), free, no
     * action cost.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function skipGolemCard(GameState $state, string $actorRole): array
    {
        self::requireWandererWithCell($state, $actorRole);

        if (! in_array('parallel', $state->golem['glyphs'], true)) {
            throw new InvalidMoveException('Parallel is not active.');
        }

        if ($state->parallelUsedThisRound) {
            throw new InvalidMoveException('Parallel has already been used this round.');
        }

        $golem = $state->golem;

        if ($golem['revealed'] === []) {
            throw new InvalidMoveException('There is no Golem card to skip.');
        }

        $skipped = array_pop($golem['revealed']);
        $card = GolemCardCatalog::find($skipped['key']);
        $golem['discard_pile'][] = $skipped['key'];
        $state = $state->with(['golem' => $golem, 'parallelUsedThisRound' => true]);
        $state = self::refillGolemRevealed($state);

        return [$state, [['type' => 'golem_card_skipped', 'role_key' => $actorRole, 'payload' => [
            'card_key' => $skipped['key'], 'name' => $card?->name, 'action' => $card?->action,
        ]]]];
    }

    /**
     * Bazaar: "Ongoing: You may tuck Relics from your mat here, if you
     * are on this space on your turn. This does not cost an action. For
     * each you tuck: 🌿1." No action cost and no 0-HP gate — same
     * "free, any time during your own turn here" category as
     * Purification pools, not a spendable Action.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function tuckRelicAtBazaar(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];
        $cellKey = Board::key($wanderer['cell']);

        if ($state->board[$cellKey]['location_key'] !== 'bazaar') {
            throw new InvalidMoveException('You must be on the Bazaar to do this.');
        }

        $relicKey = (string) ($move['relic_key'] ?? '');
        $equipped = $wanderer['equipped'];
        $fromSlot = array_search($relicKey, $equipped, true);
        $fromHolding = in_array($relicKey, $wanderer['holding'], true);

        if (! $fromSlot && ! $fromHolding) {
            throw new InvalidMoveException('You do not have that Relic.');
        }

        if ($fromSlot !== false) {
            $equipped[$fromSlot] = null;
        }

        $wanderers = $state->wanderersWith($actorRole, [
            'equipped' => $equipped,
            'holding' => array_values(array_diff($wanderer['holding'], [$relicKey])),
        ]);
        $state = $state->with(['wanderers' => $wanderers]);

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'tucked' => [...$board[$cellKey]['tucked'], $relicKey]];
        $state = $state->with(['board' => $board]);

        $events = [['type' => 'relic_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'cell' => $wanderer['cell']]]];

        $hp = min(self::maxHpFor($state, $actorRole), $state->wanderers[$actorRole]['hp'] + 1);
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
        $events[] = ['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]];

        return [$state, $events];
    }

    /**
     * Cathedral: "Ongoing: You may tuck Relics from your mat here, if
     * you are on this space on your turn. This does not cost an
     * action. End of game: Gain 📜 for each tucked Relic that has
     * 'Divine' in its name." Same free, any-time-on-your-turn shape as
     * Bazaar's tuck, just without the per-tuck heal — see
     * applyEndOfGameScoring() for the matching scoring half.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function tuckRelicAtCathedral(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];
        $cellKey = Board::key($wanderer['cell']);

        if ($state->board[$cellKey]['location_key'] !== 'cathedral') {
            throw new InvalidMoveException('You must be on the Cathedral to do this.');
        }

        $relicKey = (string) ($move['relic_key'] ?? '');
        $equipped = $wanderer['equipped'];
        $fromSlot = array_search($relicKey, $equipped, true);
        $fromHolding = in_array($relicKey, $wanderer['holding'], true);

        if (! $fromSlot && ! $fromHolding) {
            throw new InvalidMoveException('You do not have that Relic.');
        }

        if ($fromSlot !== false) {
            $equipped[$fromSlot] = null;
        }

        $wanderers = $state->wanderersWith($actorRole, [
            'equipped' => $equipped,
            'holding' => array_values(array_diff($wanderer['holding'], [$relicKey])),
        ]);
        $state = $state->with(['wanderers' => $wanderers]);

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'tucked' => [...$board[$cellKey]['tucked'], $relicKey]];
        $state = $state->with(['board' => $board]);

        $events = [['type' => 'relic_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'cell' => $wanderer['cell']]]];

        return [$state, $events];
    }

    /**
     * Garbage barricade: "Ongoing: You may tuck Relics from your mat
     * here. When the Golem attacks Wanderers in this space, reduce
     * damage by 1 for every 2 Relics tucked here." No heal (unlike
     * Bazaar) and no action cost, same free-tuck shape as Bazaar/
     * Cathedral — see applyDamage() for the matching damage-reduction
     * half.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function tuckRelicAtGarbageBarricade(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];
        $cellKey = Board::key($wanderer['cell']);

        if ($state->board[$cellKey]['location_key'] !== 'garbage-barricade') {
            throw new InvalidMoveException('You must be on the Garbage barricade to do this.');
        }

        $relicKey = (string) ($move['relic_key'] ?? '');
        $equipped = $wanderer['equipped'];
        $fromSlot = array_search($relicKey, $equipped, true);
        $fromHolding = in_array($relicKey, $wanderer['holding'], true);

        if (! $fromSlot && ! $fromHolding) {
            throw new InvalidMoveException('You do not have that Relic.');
        }

        if ($fromSlot !== false) {
            $equipped[$fromSlot] = null;
        }

        $wanderers = $state->wanderersWith($actorRole, [
            'equipped' => $equipped,
            'holding' => array_values(array_diff($wanderer['holding'], [$relicKey])),
        ]);
        $state = $state->with(['wanderers' => $wanderers]);

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'tucked' => [...$board[$cellKey]['tucked'], $relicKey]];
        $state = $state->with(['board' => $board]);

        $events = [['type' => 'relic_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'cell' => $wanderer['cell']]]];

        return [$state, $events];
    }

    /**
     * Purification pools: "Ongoing: If you begin your turn here, you
     * may remove 1 Shame token, 1 Burn token, and 1 Bound Relic from
     * your mat." (rules.md 2026-11-24 revision — previously unbounded
     * "remove Shame tokens, Scar tokens, and Bound Relics," now
     * explicitly capped at 1 each.) Modeled as a free action (no action
     * cost, callable any time during the Wanderer's own turn while on
     * this space) rather than strictly gating it to the literal first
     * instant of the turn, since there's no mid-phase pause to offer
     * the choice right then.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function cleanseAtPurificationPools(GameState $state, string $actorRole): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];
        $cellKey = Board::key($wanderer['cell']);

        if ($state->board[$cellKey]['location_key'] !== 'purification-pools') {
            throw new InvalidMoveException('You must be on the Purification pools to do this.');
        }

        $events = [];

        if ($state->wanderers[$actorRole]['shame'] > 0) {
            [$state, $e] = self::removeShame($state, $actorRole);
            $events = [...$events, ...$e];
        }

        if (in_array('burn_token', $state->wanderers[$actorRole]['equipped'], true)) {
            [$state, $e] = self::removeBurn($state, $actorRole);
            $events = [...$events, ...$e];
        }

        $equipped = $state->wanderers[$actorRole]['equipped'];
        foreach ($equipped as $slot => $value) {
            if (is_string($value) && RelicCatalog::find($value)?->isBound === true) {
                $equipped[$slot] = null;
                $state = $state->with([
                    'wanderers' => $state->wanderersWith($actorRole, ['equipped' => $equipped]),
                    'trashedRelics' => [...$state->trashedRelics, $value],
                ]);
                $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $value]];
                break;
            }
        }

        return [$state, $events];
    }

    /**
     * Magnetic mail: "If an Ally is adjacent to you on a space with a
     * Monolith Shard, you may freely move into their space at any
     * time, even if it is not your turn." Not turn-gated, no action
     * cost, no requireRole("wanderer")-style active-turn check — the
     * same "free, any time" category as pickup/drop/equip.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function magneticMailMove(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        $wanderer = $state->wanderers[$actorRole];

        if (! in_array('magnetic-mail', $wanderer['equipped'], true)) {
            throw new InvalidMoveException('Magnetic mail is not equipped.');
        }

        $targetRole = (string) ($move['target_role'] ?? '');
        $ally = $state->wanderers[$targetRole] ?? null;

        if ($ally === null || $ally['cell'] === null) {
            throw new InvalidMoveException('That Ally is not on the board.');
        }

        $casterCellKey = Board::key($wanderer['cell']);
        $allyCellKey = Board::key($ally['cell']);

        if (! in_array($allyCellKey, array_map(Board::key(...), Board::occupiedNeighbors($wanderer['cell'], $state->board)), true)) {
            throw new InvalidMoveException('That Ally is not adjacent to you.');
        }

        if (! self::hasMonolithShardFor($state, $targetRole, $ally['cell'])) {
            throw new InvalidMoveException('That Ally\'s space has no Monolith Shard.');
        }

        if (self::entryBlocked($state, $actorRole, $ally['cell'])) {
            throw new InvalidMoveException('Catacombs: only a Wanderer who has been exhausted before may enter.');
        }

        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['cell' => $ally['cell']])]);
        $events = [['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $ally['cell'], 'free' => true]]];

        [$state, $e] = self::moveDarkShacklesGolem($state, $actorRole, $wanderer['cell'], $ally['cell']);
        $events = [...$events, ...$e];

        return [$state, $events];
    }

    /**
     * Telekinesis: "When moving from a space with a Monolith Shard,
     * you may move to any space." $move['cell'] picks the destination
     * directly (any built Location) instead of a direction. Still
     * costs the normal action, same as an ordinary move.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function telekinesisMove(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];
        $originInfrastructure = $state->board[Board::key($wanderer['cell'])]['infrastructure'];

        if (! in_array('telekenesis', $wanderer['equipped'], true)) {
            throw new InvalidMoveException('Telekinesis is not equipped.');
        }

        if (! self::hasMonolithShardFor($state, $actorRole, $wanderer['cell'])) {
            throw new InvalidMoveException('You must be on a Monolith Shard space to use Telekinesis.');
        }

        $toCell = $move['cell'] ?? null;

        if (! is_array($toCell) || count($toCell) !== 2) {
            throw new InvalidMoveException('A destination cell is required.');
        }

        $toCell = [(int) $toCell[0], (int) $toCell[1]];
        $toKey = Board::key($toCell);

        if (! array_key_exists($toKey, $state->board)) {
            throw new InvalidMoveException('Wanderers must always be on a Location.');
        }

        $destinationLocationKey = $state->board[$toKey]['location_key'];
        $destinationInfrastructure = $state->board[$toKey]['infrastructure'];

        // Road: see moveWanderer()'s identical comment — the first move
        // of a turn always costs its action regardless of mechanism,
        // and (since the 2026-11-24 tightening) the destination must
        // also have a Road, same as an ordinary move off a Road space.
        $freeMove = $wanderer['has_moved_this_turn']
            && in_array('road', $originInfrastructure, true)
            && in_array('road', $destinationInfrastructure, true)
            && ! in_array('earthquake', $state->golem['glyphs'], true);

        if (! $freeMove && $wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        self::requireNotExhausted($wanderer);

        if ($wanderer['move_blocked_this_turn']) {
            throw new InvalidMoveException('Incarceration: you cannot move for the rest of this turn.');
        }

        if (self::entryBlocked($state, $actorRole, $toCell)) {
            throw new InvalidMoveException('Catacombs: only a Wanderer who has been exhausted before may enter.');
        }

        if ($destinationLocationKey === 'exile' && $wanderer['shame'] < 3) {
            throw new InvalidMoveException('Exile: only a Wanderer with 3 Shame tokens may enter.');
        }

        $wanderers = $state->wanderersWith($actorRole, [
            'cell' => $toCell,
            'actions_remaining' => $wanderer['actions_remaining'] - ($freeMove ? 0 : 1),
            'has_moved_this_turn' => true,
        ]);
        $state = $state->with(['wanderers' => $wanderers]);

        $events = [['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $toCell, 'free' => $freeMove]]];

        if ($destinationLocationKey === 'beds-of-poison') {
            [$state, $damageEvents] = self::applyDamage($state, $actorRole, 5);
            $events = [...$events, ...$damageEvents];
        }

        if (self::hasMonolithShardFor($state, $actorRole, $toCell) && in_array('rejuvenation-gown', $wanderer['equipped'], true)) {
            $hp = min(self::maxHpFor($state, $actorRole), $state->wanderers[$actorRole]['hp'] + 2);
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]];
        }

        [$state, $tiesEvents] = self::applyTiesThatBindOnMove($state, $actorRole, $toCell);
        $events = [...$events, ...$tiesEvents];

        [$state, $e] = self::moveDarkShacklesGolem($state, $actorRole, $wanderer['cell'], $toCell);
        $events = [...$events, ...$e];

        return [$state, $events];
    }

    /**
     * Laborer's Jacket: "While you are on a space that produces 🤝 at
     * the start of the Wanderer phase, you move each Ally to any
     * space." Modeled as usable once per round rather than strictly
     * gated to "the start of the Wanderer phase" specifically — there's
     * no clean seam for that exact timing (the phase transition is one
     * atomic engine call, not a window other moves can land inside),
     * and the printed condition (on a 🤝 space) still has to hold at the
     * moment of use. $move['destinations'] maps ally role => destination
     * cell; entries for unknown roles or invalid cells are silently
     * skipped rather than rejecting the whole move.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function laborersJacketMoveAllies(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        $wanderer = $state->wanderers[$actorRole];

        if (! in_array('laborers-jacket', $wanderer['equipped'], true)) {
            throw new InvalidMoveException('Laborer\'s Jacket is not equipped.');
        }

        if ($wanderer['laborers_jacket_used_this_round']) {
            throw new InvalidMoveException('Laborer\'s Jacket has already been used this round.');
        }

        $location = LocationCatalog::find($state->board[Board::key($wanderer['cell'])]['location_key']);

        if ($location?->producesEmoji !== '🤝') {
            throw new InvalidMoveException('Laborer\'s Jacket requires standing on a space that produces 🤝.');
        }

        $destinations = $move['destinations'] ?? [];
        $events = [];

        foreach (self::activeWanderers($state) as $role => $allyCell) {
            if ($role === $actorRole || ! is_array($destinations) || ! array_key_exists($role, $destinations)) {
                continue;
            }

            $toCell = $destinations[$role];

            if (! is_array($toCell) || count($toCell) !== 2) {
                continue;
            }

            $toCell = [(int) $toCell[0], (int) $toCell[1]];
            $toKey = Board::key($toCell);

            if (! array_key_exists($toKey, $state->board)) {
                continue;
            }

            if (self::entryBlocked($state, $role, $toCell)) {
                continue;
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $toCell])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $toCell, 'free' => true]];

            [$state, $e] = self::moveDarkShacklesGolem($state, $role, $allyCell, $toCell);
            $events = [...$events, ...$e];

            if (self::hasMonolithShardFor($state, $role, $toCell) && in_array('rejuvenation-gown', $state->wanderers[$role]['equipped'], true)) {
                $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 2);
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
                $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
            }

            [$state, $tiesEvents] = self::applyTiesThatBindOnMove($state, $role, $toCell);
            $events = [...$events, ...$tiesEvents];

            if ($state->board[$toKey]['location_key'] === 'beds-of-poison') {
                [$state, $damageEvents] = self::applyDamage($state, $role, 5);
                $events = [...$events, ...$damageEvents];
            }
        }

        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['laborers_jacket_used_this_round' => true])]);

        return [$state, $events];
    }

    /**
     * Relic pickup/drop/equip aren't turn-gated, but still require the
     * actor to actually be a seated Wanderer with a cell (i.e. setup is
     * done).
     */
    private static function requireWandererWithCell(GameState $state, string $actorRole): void
    {
        if ($state->phase === 'setup') {
            throw new InvalidMoveException('Setup is not finished yet.');
        }

        $wanderer = $state->wanderers[$actorRole] ?? null;

        if ($wanderer === null || $wanderer['cell'] === null) {
            throw new InvalidMoveException('You are not a seated Wanderer.');
        }
    }

    private static function relicFitsSlot(string $relicSlot, string $slot): bool
    {
        return match ($slot) {
            'body' => $relicSlot === 'Body',
            'mind', 'mind_2', 'mind_3' => $relicSlot === 'Mind',
            'bag' => $relicSlot === 'Bag',
            'hand_left', 'hand_right' => $relicSlot === 'Hand',
            default => false,
        };
    }

    /**
     * Burn is the only token that still lives in an equip slot — rules.md
     * (2026-11-24 revision): "Shame Tokens are placed in the Shame Zone
     * of the Wanderer mat," a plain counter (wanderers[role]['shame'],
     * mirroring the Golem's own golem['shame']) with no slot involved at
     * all anymore.
     */
    private static function isStatusToken(mixed $value): bool
    {
        return $value === 'burn_token';
    }

    /**
     * Shame is capped at 3 (rules.md's Relic texts reference this
     * explicitly); further gains are a no-op.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyShame(GameState $state, string $targetRole, int $amount = 1): array
    {
        // Shamlessness: "Ignore all cards that tell you to gain Shame tokens."
        if (in_array('shamlessness', $state->wanderers[$targetRole]['equipped'], true)) {
            return [$state, []];
        }

        $shame = min(3, $state->wanderers[$targetRole]['shame'] + $amount);

        if ($shame === $state->wanderers[$targetRole]['shame']) {
            return [$state, []];
        }

        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['shame' => $shame])]);

        return [$state, [['type' => 'wanderer_shamed', 'role_key' => $targetRole, 'payload' => ['shame' => $shame]]]];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function removeShame(GameState $state, string $targetRole, int $amount = 1): array
    {
        $shame = max(0, $state->wanderers[$targetRole]['shame'] - $amount);

        if ($shame === $state->wanderers[$targetRole]['shame']) {
            return [$state, []];
        }

        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['shame' => $shame])]);

        return [$state, [['type' => 'wanderer_shame_removed', 'role_key' => $targetRole, 'payload' => ['shame' => $shame]]]];
    }

    /**
     * Burn: "When a Wanderer receives a Burn Token, they choose which
     * Relic slot it goes into. If there was a Relic there, they drop
     * it. They cannot equip a Relic there until the Burn is healed."
     * Unlike the old Scar rule (empty slots only), ANY slot not already
     * burned is a legal choice — picking an occupied one drops that
     * Relic (face-up on the caster's cell, same as a deliberate drop)
     * rather than it being protected. Who picks is confirmed (the
     * affected Wanderer in co-op, the Golem-pilot in adversary mode —
     * rules.md; always the Wanderer here, adversary mode isn't
     * modeled). With 0 or 1 legal slots there's nothing to actually
     * decide, so this still applies immediately either way; with 2+, it
     * queues the choice on pendingStatusTokenChoices (resolved one at a
     * time via resolve_status_token_choice) instead of auto-picking.
     *
     * Deliberately NOT the same pendingGolemCardChoice machinery Fire
     * storm/Roar/Sacrifice of Possessions use — see this method's
     * history for the fuller comparison. Short version: whatever's
     * mid-resolution when a Burn is granted just finishes normally with
     * the placement deferred, and only the *next* move() call is
     * blocked until every queued choice is answered.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyBurn(GameState $state, string $targetRole): array
    {
        $equipped = $state->wanderers[$targetRole]['equipped'];
        $legalSlots = array_keys(array_filter($equipped, fn (mixed $v) => $v !== 'burn_token'));

        if ($legalSlots === []) {
            return [$state, []];
        }

        if (count($legalSlots) === 1) {
            return self::placeBurn($state, $targetRole, $legalSlots[0]);
        }

        $state = $state->with(['pendingStatusTokenChoices' => [
            ...$state->pendingStatusTokenChoices,
            ['role' => $targetRole, 'token' => 'burn_token'],
        ]]);

        return [$state, [['type' => 'status_token_choice_offered', 'role_key' => $targetRole, 'payload' => ['token' => 'burn_token']]]];
    }

    /**
     * The actual slot assignment, shared by applyBurn()'s single-
     * legal-slot case and resolveStatusTokenChoice()'s real choice. Any
     * Relic already in $slot is dropped face-up first.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function placeBurn(GameState $state, string $targetRole, string $slot): array
    {
        $equipped = $state->wanderers[$targetRole]['equipped'];
        $displaced = $equipped[$slot];
        $events = [];

        if (is_string($displaced)) {
            $cell = $state->wanderers[$targetRole]['cell'];
            if ($cell !== null) {
                $cellKey = Board::key($cell);
                $board = $state->board;
                $board[$cellKey] = [...$board[$cellKey], 'relics_face_up' => [...$board[$cellKey]['relics_face_up'], $displaced]];
                $state = $state->with(['board' => $board]);
            }
            $events[] = ['type' => 'relic_dropped', 'role_key' => $targetRole, 'payload' => ['relic_key' => $displaced, 'reason' => 'burn']];
        }

        $equipped[$slot] = 'burn_token';
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['equipped' => $equipped])]);
        $events[] = ['type' => 'wanderer_burned', 'role_key' => $targetRole, 'payload' => ['slot' => $slot]];

        return [$state, $events];
    }

    /**
     * Resolves the Wanderer's choice of which slot their queued Burn
     * goes into. Only the front of the queue is ever awaited at once
     * (matches a single pendingXxx resolve pattern rather than
     * pendingGolemCardChoice's multi-role 'awaiting' list, since in
     * practice these never need more than one role to answer before the
     * next move can happen). An invalid choice (already burned, or not
     * a real slot) is a no-op for this entry — still consumes it from
     * the queue rather than deadlocking, same as every other pendingXxx
     * resolve.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveStatusTokenChoice(GameState $state, string $actorRole, array $move): array
    {
        $queue = $state->pendingStatusTokenChoices;

        if ($queue === [] || $queue[0]['role'] !== $actorRole) {
            throw new InvalidMoveException('There is no status token choice pending for you.');
        }

        $state = $state->with(['pendingStatusTokenChoices' => array_slice($queue, 1)]);

        $slot = (string) ($move['slot'] ?? '');
        $equipped = $state->wanderers[$actorRole]['equipped'];

        if (! array_key_exists($slot, $equipped) || $equipped[$slot] === 'burn_token') {
            return [$state, []];
        }

        return self::placeBurn($state, $actorRole, $slot);
    }

    /**
     * Heals one Burn from $targetRole's mat (whichever slot it's in —
     * the slot becomes equippable again), if they have one. Used by
     * Holy water (either type, caster's choice), Hands of forgiveness,
     * and Purification pools.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function removeBurn(GameState $state, string $targetRole): array
    {
        $equipped = $state->wanderers[$targetRole]['equipped'];
        $slot = array_search('burn_token', $equipped, true);

        if ($slot === false) {
            return [$state, []];
        }

        $equipped[$slot] = null;
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['equipped' => $equipped])]);

        return [$state, [['type' => 'wanderer_burn_removed', 'role_key' => $targetRole, 'payload' => ['slot' => $slot]]]];
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: int, 1: int}
     */
    private static function requireCell(array $move, string $key): array
    {
        $cell = $move[$key] ?? null;

        if (! is_array($cell) || count($cell) !== 2) {
            throw new InvalidMoveException('A valid cell is required.');
        }

        return [(int) $cell[0], (int) $cell[1]];
    }
}
