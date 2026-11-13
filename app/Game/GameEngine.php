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

    private const GOLEM_MAX_GLYPHS = 4;

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    public static function apply(GameState $state, string $actorRole, array $move): array
    {
        return match ($move['type'] ?? null) {
            'choose_starting_cell' => self::chooseStartingCell($state, $actorRole, $move),
            'choose_golem_cell' => self::chooseGolemCell($state, $actorRole, $move),
            'play_location' => self::playLocation($state, $actorRole, $move),
            'place_infrastructure' => self::placeInfrastructure($state, $actorRole, $move),
            'end_emperor_phase' => self::endEmperorPhase($state, $actorRole, $move),
            'move_wanderer' => self::moveWanderer($state, $actorRole, $move),
            'search_location' => self::searchLocation($state, $actorRole),
            'equip_relic' => self::equipRelic($state, $actorRole, $move),
            'drop_relic' => self::dropRelic($state, $actorRole, $move),
            'pickup_relic' => self::pickupRelic($state, $actorRole, $move),
            'use_relic' => self::useRelic($state, $actorRole, $move),
            'end_wanderer_turn' => self::endWandererTurn($state, $actorRole),
            default => throw new InvalidMoveException('Unknown move type.'),
        };
    }

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

        [$relics, $relicDecks] = RelicDealer::deal($state->relicDecks, $location);

        $hand = array_values(array_diff($state->locationHand, [$locationKey]));
        $board = $state->boardWith($cellKey, [
            'location_key' => $locationKey,
            'placed_round' => $state->round,
            'relics' => $relics,
            'infrastructure' => [],
        ]);

        $state = $state->with(['board' => $board, 'locationHand' => $hand, 'relicDecks' => $relicDecks]);

        return [$state, [
            ['type' => 'location_played', 'role_key' => $actorRole, 'payload' => ['location_key' => $locationKey, 'cell' => $cell]],
        ]];
    }

    /**
     * Step 3 of the Emperor phase: up to 2 per turn, from the pool.
     * Monolith Shard allows only 1 per space; Walls cannot go on the
     * Golem's own space. The "cannot trap the Golem" rule isn't enforced
     * — a cooperative Emperor has no reason to actually do that, and
     * checking full board reachability after every Wall placement is more
     * than this is worth yet.
     */
    private static function placeInfrastructure(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, RoleKeys::EMPEROR, 'emperor');

        $type = (string) ($move['infrastructure_type'] ?? '');
        $cell = self::requireCell($move, 'cell');
        $cellKey = Board::key($cell);

        if ($state->infrastructurePlacedThisTurn >= 2) {
            throw new InvalidMoveException('Already placed 2 Infrastructure this turn.');
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
        ]);

        return [$state, [
            ['type' => 'infrastructure_placed', 'role_key' => $actorRole, 'payload' => ['infrastructure' => $type, 'cell' => $cell]],
        ]];
    }

    /**
     * 🔎 — draws a random Relic from the current space's face-down pile
     * into the Wanderer's hand ("holding"). Costs 1 action; an empty pile
     * still costs the action, same as a physical search coming up short.
     *
     * Event-type Relics have no equip slot — rules.md: "If you discover
     * an Event, resolve immediately and trash." Resolving the effect
     * isn't modeled, but trashing it is: it's never added to `holding`,
     * since there'd be no way to ever do anything with it there.
     */
    private static function searchLocation(GameState $state, string $actorRole): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];

        if ($wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        $cellKey = Board::key($wanderer['cell']);
        $pile = $state->board[$cellKey]['relics'];

        $found = null;
        if ($pile !== []) {
            $index = array_rand($pile);
            $found = $pile[$index];
            unset($pile[$index]);
            $pile = array_values($pile);
        }

        $isEvent = $found !== null && (RelicCatalog::find($found)?->isEvent ?? false);

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'relics' => $pile];

        $wanderers = $state->wanderersWith($actorRole, [
            'actions_remaining' => $wanderer['actions_remaining'] - 1,
            'holding' => $found !== null && ! $isEvent ? [...$wanderer['holding'], $found] : $wanderer['holding'],
        ]);

        $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);

        return [$state, [
            ['type' => 'location_searched', 'role_key' => $actorRole, 'payload' => ['found' => $found, 'trashed_as_event' => $isEvent]],
        ]];
    }

    /**
     * Not action-gated — "you may immediately equip Relics on this mat"
     * reads as an always-available administrative move, distinct from the
     * costed 🔎 action that finds one. Flagged in rules-todo.md to confirm.
     * Bumps whatever was already in that slot onto the ground.
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

        if ($relic === null || ! self::relicFitsSlot($relic->slot, $slot)) {
            throw new InvalidMoveException('That Relic does not fit that slot.');
        }

        $displaced = $wanderer['equipped'][$slot];
        $cellKey = Board::key($wanderer['cell']);
        $board = $state->board;

        if ($displaced !== null) {
            $board[$cellKey] = [...$board[$cellKey], 'relics' => [...$board[$cellKey]['relics'], $displaced]];
        }

        $equipped = $wanderer['equipped'];
        $equipped[$slot] = $relicKey;

        $wanderers = $state->wanderersWith($actorRole, [
            'equipped' => $equipped,
            'holding' => array_values(array_diff($wanderer['holding'], [$relicKey])),
        ]);

        $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);

        return [$state, [
            ['type' => 'relic_equipped', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'slot' => $slot]],
        ]];
    }

    /**
     * Free at any time, per rules.md: "You may pick up and drop Relics in
     * your space at any time. This does not cost an action."
     */
    private static function dropRelic(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        $wanderer = $state->wanderers[$actorRole];
        $relicKey = (string) ($move['relic_key'] ?? '');

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
        $board[$cellKey] = [...$board[$cellKey], 'relics' => [...$board[$cellKey]['relics'], $relicKey]];

        $wanderers = $state->wanderersWith($actorRole, [
            'equipped' => $equipped,
            'holding' => array_values(array_diff($wanderer['holding'], [$relicKey])),
        ]);

        $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);

        return [$state, [
            ['type' => 'relic_dropped', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]],
        ]];
    }

    /**
     * Free at any time — the other half of the drop rule above. Unlike
     * search, this takes a specific, known (already face-up) Relic.
     */
    private static function pickupRelic(GameState $state, string $actorRole, array $move): array
    {
        self::requireWandererWithCell($state, $actorRole);

        $wanderer = $state->wanderers[$actorRole];
        $relicKey = (string) ($move['relic_key'] ?? '');
        $cellKey = Board::key($wanderer['cell']);
        $pile = $state->board[$cellKey]['relics'];

        if (! in_array($relicKey, $pile, true)) {
            throw new InvalidMoveException('That Relic is not here.');
        }

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'relics' => array_values(array_diff($pile, [$relicKey]))];

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
     */
    private static function useRelic(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];

        if ($wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        $relicKey = (string) ($move['relic_key'] ?? '');

        if (! in_array($relicKey, $wanderer['equipped'], true)) {
            throw new InvalidMoveException('That Relic is not equipped.');
        }

        $relic = RelicCatalog::find($relicKey);
        $baseKey = (string) preg_replace('/-\d+$/', '', $relicKey);

        $wanderers = $state->wanderersWith($actorRole, ['actions_remaining' => $wanderer['actions_remaining'] - 1]);
        $state = $state->with(['wanderers' => $wanderers]);

        $events = [
            ['type' => 'relic_used', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'name' => $relic?->name]],
        ];

        [$state, $relicEvents, $trash] = self::resolveRelicAction($state, $actorRole, $baseKey, $move);
        $events = [...$events, ...$relicEvents];

        if ($trash) {
            $state = self::trashEquippedRelic($state, $actorRole, $relicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]];
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool} 2 = whether the Relic trashes itself after use
     */
    private static function resolveRelicAction(GameState $state, string $actorRole, string $baseKey, array $move): array
    {
        return match ($baseKey) {
            'artists-insight' => [...self::relicMove($state, $actorRole, $move, 2), false],
            'a-keen-eye' => [...self::relicSearchTwice($state, $actorRole), false],
            'alms-for-the-poor' => [...self::relicHealBelowThreshold($state, 6), false],
            'burial-robes' => [...self::relicHealSharedCell($state, $actorRole, 15), false],
            'divine-healing-oil' => [...self::relicHealSelfOrAlly($state, $actorRole, $move, 3), false],
            'golem-disguise' => [...self::relicDiscardGolemTopCard($state), false],
            'golem-whistle' => [...self::relicSummonGolem($state, $actorRole), false],
            'quantum-robes' => [...self::relicSwapWithAlly($state, $actorRole, $move), false],
            'last-rites' => [...self::relicHealAllyToMax($state, $actorRole, $move), true],
            default => [$state, [], false],
        };
    }

    /**
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

            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['cell' => $to])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $to, 'free' => true]];
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicSearchTwice(GameState $state, string $actorRole): array
    {
        $events = [];

        for ($i = 0; $i < 2; $i++) {
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

            $isEvent = RelicCatalog::find($found)?->isEvent ?? false;

            $board = $state->board;
            $board[$cellKey] = [...$board[$cellKey], 'relics' => $pile];

            $wanderers = $state->wanderersWith($actorRole, [
                'holding' => $isEvent ? $wanderer['holding'] : [...$wanderer['holding'], $found],
            ]);

            $state = $state->with(['board' => $board, 'wanderers' => $wanderers]);
            $events[] = ['type' => 'location_searched', 'role_key' => $actorRole, 'payload' => ['found' => $found, 'trashed_as_event' => $isEvent]];
        }

        return [$state, $events];
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

            $hp = min(15, $state->wanderers[$role]['hp'] + $amount);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHealSelfOrAlly(GameState $state, string $actorRole, array $move, int $amount): array
    {
        $targetRole = self::resolveAllyTarget($state, $actorRole, $move) ?? $actorRole;
        $hp = min(15, $state->wanderers[$targetRole]['hp'] + $amount);
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['hp' => $hp])]);

        return [$state, [['type' => 'wanderer_healed', 'role_key' => $targetRole, 'payload' => ['hp' => $hp]]]];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicHealAllyToMax(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = self::resolveAllyTarget($state, $actorRole, $move);

        if ($targetRole === null) {
            return [$state, []];
        }

        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['hp' => 15])]);

        return [$state, [['type' => 'wanderer_healed', 'role_key' => $targetRole, 'payload' => ['hp' => 15]]]];
    }

    /**
     * $move['target_role'], if given, must be another Wanderer sharing
     * the actor's current space — an "Ally" per rules.md.
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
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicDiscardGolemTopCard(GameState $state): array
    {
        $golem = $state->golem;

        if ($golem['draw_pile'] === []) {
            return [$state, []];
        }

        $discarded = array_shift($golem['draw_pile']);
        $golem['discard_pile'][] = $discarded;
        $state = $state->with(['golem' => $golem]);

        return [$state, [['type' => 'golem_card_discarded', 'role_key' => null, 'payload' => ['card_key' => $discarded]]]];
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

        $state = $state->with(['golem' => $state->golemWith(['cell' => $casterCell])]);

        return [$state, [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $casterCell]]]];
    }

    /**
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

        $wanderers = $state->wanderersWith($actorRole, ['cell' => $targetCell]);
        $state = $state->with(['wanderers' => $wanderers]);
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['cell' => $actorCell])]);

        return [$state, [['type' => 'wanderers_swapped', 'role_key' => $actorRole, 'payload' => ['with' => $targetRole]]]];
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

        return $state->with(['wanderers' => $state->wanderersWith($actorRole, ['equipped' => $equipped])]);
    }

    /**
     * Covers Emperor-phase steps 4 (discard) and 5 (draw back to 6) in one
     * action, then hands off to the Wanderer phase. Infrastructure (step
     * 3) isn't modeled yet; Relic dealing (step 2) happens automatically
     * in playLocation() rather than as a separate step here.
     */
    private static function endEmperorPhase(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, RoleKeys::EMPEROR, 'emperor');

        $discard = array_values(array_intersect($state->locationHand, $move['discard'] ?? []));
        $hand = array_values(array_diff($state->locationHand, $discard));
        $discardPile = [...$state->locationDiscard, ...$discard];
        $deck = $state->locationDeck;

        $needed = 6 - count($hand);
        if ($needed > 0 && count($deck) < $needed && $discardPile !== []) {
            shuffle($discardPile);
            $deck = [...$deck, ...$discardPile];
            $discardPile = [];
        }

        $drawn = array_splice($deck, 0, max(0, $needed));
        $hand = [...$hand, ...$drawn];

        $wandererOrder = RoleKeys::wanderers($state->wandererCount);
        $firstRole = $wandererOrder[0];
        $actionsPerTurn = $state->actionsPerWandererTurn();

        $wanderers = $state->wanderers;
        $wanderers[$firstRole] = [...$wanderers[$firstRole], 'actions_remaining' => $actionsPerTurn];

        $state = $state->with([
            'locationHand' => array_values($hand),
            'locationDiscard' => array_values($discardPile),
            'locationDeck' => array_values($deck),
            'wanderers' => $wanderers,
            'phase' => 'wanderer',
            'wandererOrder' => $wandererOrder,
            'activeWandererIndex' => 0,
        ]);

        return [$state, [
            ['type' => 'emperor_phase_ended', 'role_key' => $actorRole, 'payload' => ['discarded' => $discard]],
            ['type' => 'wanderer_turn_started', 'role_key' => $firstRole, 'payload' => ['actions' => $actionsPerTurn]],
        ]];
    }

    /**
     * Road: "When Wanderers move into a space that contains a Road, they
     * can continue into an adjacent space for free." Modeled as a
     * one-hop-ahead flag set on arrival at a Road space and consumed by
     * the very next move — chains naturally through consecutive Road
     * spaces since each such arrival re-sets it.
     *
     * Fruit tree: "When Wanderers enter this space, they gain 1 action
     * that they must use on this turn." Modeled as +1 action immediately;
     * the "must use it this turn" constraint isn't separately enforced.
     */
    private static function moveWanderer(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];
        $freeMove = $wanderer['free_move_available'];

        if (! $freeMove && $wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        $direction = (string) ($move['direction'] ?? '');
        $to = Board::neighbor($wanderer['cell'], $direction);
        $toKey = Board::key($to);

        if (! array_key_exists($toKey, $state->board)) {
            throw new InvalidMoveException('Wanderers must always be on a Location.');
        }

        $destinationInfrastructure = $state->board[$toKey]['infrastructure'];
        $actionsRemaining = $wanderer['actions_remaining'] - ($freeMove ? 0 : 1);

        if (in_array('fruit_tree', $destinationInfrastructure, true)) {
            $actionsRemaining++;
        }

        $wanderers = $state->wanderersWith($actorRole, [
            'cell' => $to,
            'actions_remaining' => $actionsRemaining,
            'free_move_available' => in_array('road', $destinationInfrastructure, true),
        ]);

        $state = $state->with(['wanderers' => $wanderers]);

        $events = [['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $to, 'free' => $freeMove]]];

        if (in_array('fruit_tree', $destinationInfrastructure, true)) {
            $events[] = ['type' => 'fruit_tree_bonus_action', 'role_key' => $actorRole, 'payload' => []];
        }

        return [$state, $events];
    }

    private static function endWandererTurn(GameState $state, string $actorRole): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $nextIndex = $state->activeWandererIndex + 1;

        if ($nextIndex < count($state->wandererOrder)) {
            $nextRole = $state->wandererOrder[$nextIndex];
            $actionsPerTurn = $state->actionsPerWandererTurn();

            $wanderers = $state->wanderers;
            $wanderers[$nextRole] = [...$wanderers[$nextRole], 'actions_remaining' => $actionsPerTurn];

            $state = $state->with(['wanderers' => $wanderers, 'activeWandererIndex' => $nextIndex]);

            return [$state, [
                ['type' => 'wanderer_turn_started', 'role_key' => $nextRole, 'payload' => ['actions' => $actionsPerTurn]],
            ]];
        }

        return self::endRound($state);
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
        [$state, $events] = self::resolveGolemPhase($state);

        if ($state->round >= self::ROUND_LIMIT) {
            $state = $state->with(['phase' => 'finished', 'activeWandererIndex' => null]);
            $events[] = ['type' => 'game_finished', 'role_key' => null, 'payload' => []];

            return [$state, $events];
        }

        $nextRound = $state->round + 1;
        $state = $state->with([
            'round' => $nextRound,
            'phase' => 'emperor',
            'activeWandererIndex' => null,
            'infrastructurePlacedThisTurn' => 0,
        ]);
        $events[] = ['type' => 'round_started', 'role_key' => null, 'payload' => ['round' => $nextRound]];

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveGolemPhase(GameState $state): array
    {
        $events = [];
        $toPlay = $state->golemCardsThisRound();

        for ($i = 0; $i < $toPlay; $i++) {
            $golem = $state->golem;

            if ($golem['revealed'] === []) {
                break; // deck + discard both exhausted — nothing left to resolve
            }

            $cardKey = array_pop($golem['revealed']);
            $golem['discard_pile'][] = $cardKey;
            $state = $state->with(['golem' => $golem]);

            [$state, $cardEvents, $effects] = self::resolveGolemCard($state, $cardKey);
            $events = [...$events, ...$cardEvents];

            if ($effects['double_next'] && $i + 1 < $toPlay) {
                // "Perform the next Golem card twice" — doubles the next
                // physical card's effect without consuming an extra one of
                // this round's N plays; the loop's own i++ still advances
                // onto it normally afterward.
                $golem = $state->golem;
                if ($golem['revealed'] !== []) {
                    $nextKey = $golem['revealed'][array_key_last($golem['revealed'])];
                    [$state, $extraEvents] = self::resolveGolemCard($state, $nextKey);
                    $events = [...$events, ...$extraEvents];
                }
            }

            $toPlay += $effects['extra_cards'];

            $state = self::refillGolemRevealed($state);
        }

        return [$state, $events];
    }

    /**
     * Keeps the revealed row topped up to 3 after each resolution,
     * reshuffling the discard pile back into the draw pile if it runs dry
     * — same rule as the Location deck.
     */
    private static function refillGolemRevealed(GameState $state): GameState
    {
        $golem = $state->golem;

        while (count($golem['revealed']) < self::GOLEM_REVEALED_SIZE) {
            if ($golem['draw_pile'] === []) {
                if ($golem['discard_pile'] === []) {
                    break;
                }

                $golem['draw_pile'] = $golem['discard_pile'];
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
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: array{double_next: bool, extra_cards: int}}
     */
    private static function resolveGolemCard(GameState $state, string $cardKey): array
    {
        $card = GolemCardCatalog::find($cardKey);
        $baseKey = (string) preg_replace('/-\d+$/', '', $cardKey);
        $effects = ['double_next' => false, 'extra_cards' => 0];

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

            case 'penitence':
                $state = $state->with(['golem' => $state->golemWith(['shame' => max(0, $state->golem['shame'] - 1)])]);
                break;

            case 'double-down':
                $effects['double_next'] = true;
                break;

            case 'time-paradox':
                $effects['extra_cards'] = 2;
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
        $golemCell = $state->golem['cell'];

        if ($targetRole === null || $golemCell === null) {
            return [$state, []];
        }

        $path = Board::shortestPath($golemCell, $state->wanderers[$targetRole]['cell'], $state->board, $state->wallKeys());

        if ($path === null || $path === []) {
            return [$state, []];
        }

        $steps = array_slice($path, 0, $maxSteps);
        $destination = $steps[array_key_last($steps)];

        $state = $state->with(['golem' => $state->golemWith(['cell' => $destination])]);

        return [$state, [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $destination]]]];
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

        return self::damageWanderersAt($state, [$golemCell], $damage);
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

        return self::damageWanderersAt($state, $cells, $damage);
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemDamageAllWanderers(GameState $state, int $damage): array
    {
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            $state = self::applyDamage($state, $role, $damage);
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => $damage]];
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
                $state = self::applyDamage($state, $role, $damage);
                $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => $damage]];
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
    private static function damageWanderersAt(GameState $state, array $cells, int $damage): array
    {
        $cellKeys = array_map(Board::key(...), $cells);
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            $state = self::applyDamage($state, $role, $damage);
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => $damage]];
        }

        return [$state, $events];
    }

    private static function applyDamage(GameState $state, string $role, int $damage): GameState
    {
        $hp = max(0, $state->wanderers[$role]['hp'] - $damage);

        return $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
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

    private static function extremeWanderer(GameState $state, \Closure $isBetter): ?string
    {
        $golemCell = $state->golem['cell'];

        if ($golemCell === null) {
            return null;
        }

        $best = null;
        $bestDistance = null;

        foreach (self::activeWanderers($state) as $role => $cell) {
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

    private static function leastHpWanderer(GameState $state): ?string
    {
        $best = null;
        $bestHp = null;

        foreach (self::activeWanderers($state) as $role => $cell) {
            $hp = $state->wanderers[$role]['hp'];

            if ($bestHp === null || $hp < $bestHp) {
                $best = $role;
                $bestHp = $hp;
            }
        }

        return $best;
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function addGlyphOfType(GameState $state, string $type): array
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

        $state = $state->with(['golem' => $state->golemWith(['glyphs' => $glyphs]), 'glyphDeck' => $deck]);

        $events = [['type' => 'glyph_added', 'role_key' => null, 'payload' => ['glyph_key' => $glyphKey]]];

        if ($discardedGlyph !== null) {
            $events[] = ['type' => 'glyph_discarded', 'role_key' => null, 'payload' => ['glyph_key' => $discardedGlyph]];
        }

        return [$state, $events];
    }

    /**
     * Whether a neighbor of $cell produces $branch — either directly, or
     * (confirmed via chat 2026-11-12, not yet in rules.md — see
     * rules-todo.md) because that neighbor has a Road connecting it to a
     * network of other Road spaces, any of which produces it.
     */
    private static function effectiveProduces(GameState $state, array $cell, string $branch): bool
    {
        foreach (Board::occupiedNeighbors($cell, $state->board) as $neighbor) {
            $entry = $state->board[Board::key($neighbor)];
            $location = LocationCatalog::find($entry['location_key']);

            if ($location?->produces === $branch) {
                return true;
            }

            if (in_array('road', $entry['infrastructure'], true)
                && in_array($branch, self::roadNetworkProduces($state, $neighbor), true)) {
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
            'mind' => $relicSlot === 'Mind',
            'bag' => $relicSlot === 'Bag',
            'hand_left', 'hand_right' => $relicSlot === 'Hand',
            default => false,
        };
    }

    /**
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
