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

    // rules.md's Emperor phase step 5 — also the starting hand size
    // (GameSetup), so the hand is always exactly 5 between turns.
    private const EMPEROR_HAND_SIZE = 5;

    /**
     * @param  array<string, mixed>  $move
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
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
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

        $foundRelic = $found !== null ? RelicCatalog::find($found) : null;
        $isEvent = $foundRelic !== null && $foundRelic->isEvent;

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
     * Free at any time — confirmed via chat 2026-11-13. Bumps whatever
     * Relic was already in that slot onto the ground; a slot held by a
     * Shame/Scar status token can't be equipped into at all, rules.md:
     * "No Relic can go in that slot while the token remains."
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

        if ($relic === null || ! self::relicFitsSlot($relic->slot, $slot)) {
            throw new InvalidMoveException('That Relic does not fit that slot.');
        }

        $displaced = $wanderer['equipped'][$slot];

        if (self::isStatusToken($displaced)) {
            throw new InvalidMoveException('That slot is blocked by a status token.');
        }

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

        $relicKey = (string) ($move['relic_key'] ?? '');

        if (self::isStatusToken($relicKey) || ! in_array($relicKey, $wanderer['equipped'], true)) {
            throw new InvalidMoveException('That Relic is not equipped.');
        }

        $relic = RelicCatalog::find($relicKey);
        $baseKey = (string) preg_replace('/-\d+$/', '', $relicKey);

        $wanderers = $state->wanderersWith($actorRole, ['actions_remaining' => $wanderer['actions_remaining'] - 1]);
        $state = $state->with(['wanderers' => $wanderers]);

        $events = [
            ['type' => 'relic_used', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'name' => $relic?->name]],
        ];

        [$state, $relicEvents, $trash] = self::resolveRelicAction($state, $actorRole, $baseKey, $relicKey, $move);
        $events = [...$events, ...$relicEvents];

        if ($trash) {
            $state = self::trashEquippedRelic($state, $actorRole, $relicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]];
        }

        return [$state, $events];
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
            'divine-healing-oil' => [...self::relicHealSelfOrAlly($state, $actorRole, $move, 3), false],
            'golem-disguise' => [...self::relicDiscardGolemTopCard($state), false],
            'golem-whistle' => [...self::relicSummonGolem($state, $actorRole), false],
            'quantum-robes' => [...self::relicSwapWithAlly($state, $actorRole, $move), false],
            'last-rites' => [...self::relicHealAllyToMax($state, $actorRole, $move), true],
            'holy-water' => [...self::relicRemoveStatus($state, $actorRole, $move), false],
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
            default => [$state, [], false],
        };
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

            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['cell' => $to])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $to, 'free' => true]];
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
     * @param  array<string, mixed>  $move
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
     * @param  array<string, mixed>  $move
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
     * Holy water: "Remove 1 Shame or 1 Scar from yourself or an Ally in
     * your space." $move['remove_type'] picks which; defaults to
     * removing Shame if present, else Scar.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicRemoveStatus(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = self::resolveSameSpaceTarget($state, $actorRole, $move) ?? $actorRole;
        $equipped = $state->wanderers[$targetRole]['equipped'];

        $removeType = $move['remove_type'] ?? null;

        if (! in_array($removeType, ['shame_token', 'scar_token'], true)) {
            $removeType = in_array('shame_token', $equipped, true) ? 'shame_token' : 'scar_token';
        }

        return self::removeStatusToken($state, $targetRole, $removeType);
    }

    /**
     * Hands of forgiveness: "Choose a Wanderer in your space, and remove
     * all of their Shame and Scar tokens."
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicRemoveAllStatus(GameState $state, string $actorRole, array $move): array
    {
        $targetRole = self::resolveSameSpaceTarget($state, $actorRole, $move);

        if ($targetRole === null) {
            return [$state, []];
        }

        $events = [];
        [$state, $e] = self::removeStatusToken($state, $targetRole, 'shame_token');
        $events = [...$events, ...$e];
        [$state, $e] = self::removeStatusToken($state, $targetRole, 'scar_token');
        $events = [...$events, ...$e];

        // Hands of forgiveness only has one of each token to actually
        // clear at a time per removeStatusToken call; repeat while any
        // remain, since a Wanderer could in principle hold more than one
        // of the same type across different slots.
        while (in_array('shame_token', $state->wanderers[$targetRole]['equipped'], true)) {
            [$state, $e] = self::removeStatusToken($state, $targetRole, 'shame_token');
            $events = [...$events, ...$e];
        }

        while (in_array('scar_token', $state->wanderers[$targetRole]['equipped'], true)) {
            [$state, $e] = self::removeStatusToken($state, $targetRole, 'scar_token');
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
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: int} 2 = how many were actually tapped
     */
    private static function tapGolemDrawPile(GameState $state, int $count): array
    {
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

        [$state, $events] = self::tapGolemDrawPile($state, $state->golem['shame']);

        return [$state, $events, true];
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
     * 5 Health. If the Golem is in your space, tap the next un-tapped 4
     * cards from their draw pile. Then, trash this, and gain 📜." The
     * damage always lands; the tap is conditional on the Golem sharing
     * the caster's space; the trash (like every "gain 📜" clause) is
     * unconditional, same as a normal one-shot Action Relic.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicBlindingExplosion(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $affected = [$casterCell, ...Board::occupiedNeighbors($casterCell, $state->board)];

        [$state, $events] = self::damageWanderersAt($state, $affected, 5);

        if (self::golemSharesCellWith($state, $actorRole)) {
            [$state, $tapEvents] = self::tapGolemDrawPile($state, 4);
            $events = [...$events, ...$tapEvents];
        }

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

        return [$state, [['type' => 'golem_moved', 'role_key' => $actorRole, 'payload' => ['to' => $destination]]]];
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
     * Pointed finger: "If you are on the Golem's space, add one Shame
     * token to the Golem, and one to yourself. Then, trash this, and
     * gain 📜." The Golem's own Shame counter has no printed cap
     * (unlike a Wanderer's 5 equip slots) anywhere else in the engine,
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

        [$state, $e] = self::applyStatusToken($state, $actorRole, 'shame_token');
        $events = [...$events, ...$e];

        return [$state, $events, true];
    }

    /**
     * Divine aura: "If you are on a space with a Monolith Shard, 🌿2 for
     * all Wanderers on or adjacent to your space."
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicDivineAura(GameState $state, string $actorRole): array
    {
        $casterCell = $state->wanderers[$actorRole]['cell'];

        if (! in_array('monolith_shard', $state->board[Board::key($casterCell)]['infrastructure'], true)) {
            return [$state, []];
        }

        $cellKeys = [Board::key($casterCell), ...array_map(Board::key(...), Board::occupiedNeighbors($casterCell, $state->board))];
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            $hp = min(15, $state->wanderers[$role]['hp'] + 2);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        return [$state, $events];
    }

    /**
     * Emperor's Staff: "If your space does not have a Monolith Shard,
     * create one." Draws from the Emperor's fixed Infrastructure pool
     * like a normal placement would — a no-op if the pool is empty,
     * since there's no other source of Monolith Shards in rules.md.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicEmperorsStaff(GameState $state, string $actorRole): array
    {
        $cellKey = Board::key($state->wanderers[$actorRole]['cell']);
        $entry = $state->board[$cellKey];

        if (in_array('monolith_shard', $entry['infrastructure'], true)) {
            return [$state, []];
        }

        if (($state->infrastructurePool['monolith_shard'] ?? 0) <= 0) {
            return [$state, []];
        }

        $board = $state->board;
        $board[$cellKey] = [...$entry, 'infrastructure' => [...$entry['infrastructure'], 'monolith_shard']];
        $pool = $state->infrastructurePool;
        $pool['monolith_shard']--;
        $state = $state->with(['board' => $board, 'infrastructurePool' => $pool]);

        return [$state, [['type' => 'infrastructure_placed', 'role_key' => $actorRole, 'payload' => ['infrastructure' => 'monolith_shard', 'cell' => $state->wanderers[$actorRole]['cell']]]]];
    }

    /**
     * Ban of dangerous ideas: "Any Wanderer or Golem on a space that
     * produces 📖, 💭, 🎨, or 🪙 gains 1 Shame token (max 3 Shame
     * tokens). Then, trash this." The Golem has no equip slots to hold a
     * Shame token in, so (like Pointed finger) its Shame is tracked on
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

            [$state, $e] = self::applyStatusToken($state, $role, 'shame_token');
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
                [$state, $e] = self::applyStatusToken($state, $role, 'shame_token');
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

        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['cell' => $destination])]);

        return [$state, [['type' => 'wanderer_moved', 'role_key' => $targetRole, 'payload' => ['to' => $destination]]]];
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
        $index = array_search($relicKey, $fromEntry['relics'], true);

        if ($index === false) {
            return [$state, []];
        }

        $fromRelics = $fromEntry['relics'];
        unset($fromRelics[$index]);
        $fromRelics = array_values($fromRelics);

        $board = $state->board;
        $board[$fromKey] = [...$fromEntry, 'relics' => $fromRelics];
        $board[$toKey] = [...$board[$toKey], 'relics' => [...$board[$toKey]['relics'], $relicKey]];
        $state = $state->with(['board' => $board]);

        return [$state, [['type' => 'relic_moved', 'role_key' => null, 'payload' => ['relic_key' => $relicKey, 'from' => $fromKey, 'to' => $toKey]]]];
    }

    /**
     * Dispatches each "Inscribe" card on its full disambiguated key —
     * see resolveRelicAction's docblock for why. All 12 add a specific
     * Glyph type under a specific condition; all but inscribe-12 then
     * trash themselves (per each one's own printed text).
     *
     * inscribe-7 ("When the Golem hits you, add a 🌑 Glyph...") is a
     * reactive trigger, not a player-initiated action — there's no
     * damage-event hook for Relics to react to yet, so it's a no-op
     * like any other unimplemented Relic.
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
                ? [...self::addGlyphOfType($state, '⏳'), true]
                : $noOp,

            'inscribe-4' => self::inscribePrisonJudgment($state, $actorRole),

            'inscribe-5' => $onGolemSpace && self::countStatusTokens($state->wanderers[$actorRole]['equipped'], 'shame_token') === 0
                ? [...self::addGlyphOfType($state, '⚖️'), true]
                : $noOp,

            'inscribe-6' => $onGolemSpace && in_array('monolith_shard', $state->board[Board::key($casterCell)]['infrastructure'], true)
                ? [...self::addGlyphOfType($state, '✨'), true]
                : $noOp,

            'inscribe-7' => $noOp,

            'inscribe-8' => $onGolemSpace
                ? [...self::addGlyphOfType($state, '🎨'), true]
                : $noOp,

            'inscribe-9' => $onGolemSpace
                ? [...self::addGlyphOfType($state, '🐾'), true]
                : $noOp,

            'inscribe-10' => $onGolemSpace
                ? [...self::addGlyphOfType($state, '📖'), true]
                : $noOp,

            'inscribe-11' => self::allWanderersShareGolemSpace($state)
                ? [...self::addGlyphOfType($state, '🤝'), true]
                : $noOp,

            'inscribe-12' => $onGolemSpace && self::allWanderersMutuallyAtLeastDistance($state, 2)
                ? [...self::addGlyphOfType($state, '🤝'), false]
                : $noOp,

            default => $noOp,
        };
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
        $casterCell = $state->wanderers[$actorRole]['cell'];
        $onPrison = $state->board[Board::key($casterCell)]['location_key'] === 'prison';
        $golemThere = self::golemSharesCellWith($state, $actorRole);
        $noShame = self::countStatusTokens($state->wanderers[$actorRole]['equipped'], 'shame_token') === 0;
        $golemHighShame = $state->golem['shame'] >= 3;

        if (! ($onPrison && $golemThere && $noShame && $golemHighShame)) {
            return [$state, [], false];
        }

        return [...self::addGlyphOfType($state, '⚖️'), true];
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

        $state = $state->with(['golem' => $state->golemWith(['cell' => $casterCell])]);

        return [$state, [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $casterCell]]]];
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

        $wandererOrder = RoleKeys::wanderers($state->wandererCount);
        $firstRole = $wandererOrder[0];
        $actionsPerTurn = $state->actionsPerWandererTurn();

        $wanderers = $state->wanderers;
        $wanderers[$firstRole] = [...$wanderers[$firstRole], 'actions_remaining' => $actionsPerTurn];

        $state = $state->with([
            'locationHand' => $hand,
            'locationDiscard' => $discardPile,
            'locationDeck' => $deck,
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
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
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

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
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

            $cardEntry = array_pop($golem['revealed']);
            $golem['discard_pile'][] = $cardEntry['key'];
            $state = $state->with(['golem' => $golem]);

            [$state, $cardEvents, $effects] = self::resolveOrSkipTappedCard($state, $cardEntry);
            $events = [...$events, ...$cardEvents];

            if ($effects['double_next'] && $i + 1 < $toPlay) {
                // "Perform the next Golem card twice" — doubles the next
                // physical card's effect without consuming an extra one of
                // this round's N plays; the loop's own i++ still advances
                // onto it normally afterward.
                $golem = $state->golem;
                if ($golem['revealed'] !== []) {
                    $nextEntry = $golem['revealed'][array_key_last($golem['revealed'])];
                    [$state, $extraEvents] = self::resolveOrSkipTappedCard($state, $nextEntry);
                    $events = [...$events, ...$extraEvents];
                }
            }

            $toPlay += $effects['extra_cards'];

            $state = self::refillGolemRevealed($state);
        }

        return [$state, $events];
    }

    /**
     * @param  array{key: string, tapped: bool}  $cardEntry
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: array{double_next: bool, extra_cards: int}}
     */
    private static function resolveOrSkipTappedCard(GameState $state, array $cardEntry): array
    {
        if ($cardEntry['tapped']) {
            $card = GolemCardCatalog::find($cardEntry['key']);

            return [$state, [
                ['type' => 'golem_card_tapped', 'role_key' => null, 'payload' => ['card_key' => $cardEntry['key'], 'name' => $card?->name]],
            ], ['double_next' => false, 'extra_cards' => 0]];
        }

        return self::resolveGolemCard($state, $cardEntry['key']);
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

            case 'scar':
                $target = self::nearestWanderer($state);
                [$state, $e] = self::golemMoveToward($state, $target, 1);
                $events = [...$events, ...$e];

                if ($target !== null) {
                    [$state, $e] = self::applyStatusToken($state, $target, 'scar_token');
                    $events = [...$events, ...$e];
                }
                break;

            case 'public-lashings':
                $target = self::mostShameWanderer($state);
                [$state, $e] = self::golemMoveToward($state, $target, 1);
                $events = [...$events, ...$e];

                if ($target !== null && self::golemSharesCellWith($state, $target)) {
                    $shame = self::countStatusTokens($state->wanderers[$target]['equipped'], 'shame_token');

                    if ($shame > 0) {
                        $state = self::applyDamage($state, $target, 3 * $shame);
                        $events[] = ['type' => 'wanderer_damaged', 'role_key' => $target, 'payload' => ['amount' => 3 * $shame]];
                    }
                }
                break;

            case 'public-shaming':
                foreach (self::activeWanderers($state) as $role => $cell) {
                    $shame = self::countStatusTokens($state->wanderers[$role]['equipped'], 'shame_token');

                    if ($shame === 0) {
                        continue;
                    }

                    $state = self::applyDamage($state, $role, 2 * $shame);
                    $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => 2 * $shame]];
                }
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

            case 'deface':
                [$state, $e] = self::golemDeface($state);
                $events = [...$events, ...$e];
                break;

            case 'fire-storm':
                [$state, $e] = self::golemFireStorm($state);
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
                [$state, $e] = self::golemRoar($state);
                $events = [...$events, ...$e];
                break;

            case 'sacrifice-of-possessions':
                [$state, $e] = self::golemSacrificeOfPossessions($state);
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

        return [$state, [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $destination]]]];
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
     * simplification as applyStatusToken's slot auto-pick, for the same
     * reason (no mid-phase interactive-pause system exists yet).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function trashFirstEquippedRelic(GameState $state, string $role): array
    {
        $equipped = $state->wanderers[$role]['equipped'];

        foreach ($equipped as $slot => $value) {
            if ($value !== null && ! self::isStatusToken($value)) {
                $equipped[$slot] = null;
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['equipped' => $equipped])]);

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
     * who lost Health as a result trashes one equipped Relic.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemFireStorm(GameState $state): array
    {
        $cellKeys = array_map(Board::key(...), self::golemCellsOnAndAdjacent($state));
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            $hpBefore = $state->wanderers[$role]['hp'];
            $state = self::applyDamage($state, $role, 3);
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => 3]];

            if ($hpBefore > 0) {
                [$state, $e] = self::trashFirstEquippedRelic($state, $role);
                $events = [...$events, ...$e];
            }
        }

        return [$state, $events];
    }

    /**
     * Great Sweeping Scythe: 👊5 to Wanderers on/adjacent to the Golem's
     * space; give a Scar token to anyone who lost Health.
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
            $state = self::applyDamage($state, $role, 5);
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => 5]];

            if ($hpBefore > 0) {
                [$state, $e] = self::applyStatusToken($state, $role, 'scar_token');
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

            $state = self::applyDamage($state, $role, $count);
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => $count]];
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

                $state = self::applyDamage($state, $role, 2);
                $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => 2]];
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
     * then move 1 space away. "A direction they choose" is simplified to
     * the neighboring space farthest from the Golem (ties broken by
     * iteration order).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemRoar(GameState $state): array
    {
        $golemCell = $state->golem['cell'];
        $cellKeys = array_map(Board::key(...), self::golemCellsOnAndAdjacent($state));
        $events = [];
        $affectedRoles = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            $state = self::applyDamage($state, $role, 3);
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => 3]];
            $affectedRoles[] = $role;
        }

        foreach ($affectedRoles as $role) {
            $cell = $state->wanderers[$role]['cell'];
            $best = null;
            $bestDistance = -1;

            foreach (Board::occupiedNeighbors($cell, $state->board) as $option) {
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
        }

        return [$state, $events];
    }

    /**
     * Sacrifice of Possessions: every Wanderer trashes one equipped
     * Relic.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function golemSacrificeOfPossessions(GameState $state): array
    {
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            [$state, $e] = self::trashFirstEquippedRelic($state, $role);
            $events = [...$events, ...$e];
        }

        return [$state, $events];
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

        [$state, $damageEvents] = self::damageWanderersAt($state, [$target, ...Board::occupiedNeighbors($target, $state->board)], 2);
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
            [$state, $damageEvents] = self::damageWanderersAt($state, Board::occupiedNeighbors($bestWall, $state->board), 4);
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
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $destination])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $destination, 'forced' => true]];
        }

        [$state, $damageEvents] = self::damageWanderersAt($state, [$golemCell], 3);
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

        $state = self::applyDamage($state, $target, 5);
        $events[] = ['type' => 'wanderer_damaged', 'role_key' => $target, 'payload' => ['amount' => 5]];

        foreach ($allies as $role) {
            $state = self::applyDamage($state, $role, 5);
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => 5]];
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

    private static function mostShameWanderer(GameState $state): ?string
    {
        $best = null;
        $bestShame = -1;

        foreach (self::activeWanderers($state) as $role => $cell) {
            $shame = self::countStatusTokens($state->wanderers[$role]['equipped'], 'shame_token');

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

    private static function isStatusToken(mixed $value): bool
    {
        return $value === 'shame_token' || $value === 'scar_token';
    }

    /**
     * Shame is capped at 3 (rules.md's Relic texts reference this
     * explicitly); further gains are a no-op. Scar has no printed cap —
     * bounded only by there being 5 equip slots.
     *
     * Who picks *which* slot is confirmed (the affected Wanderer in
     * co-op, the Golem-pilot in adversary mode — rules.md), but applying
     * this mid-way through an already-automatic Golem card resolution has
     * no interactive pause to ask either of them; this always picks the
     * first empty slot instead, or does nothing if every slot is full.
     * Flagged as a real gap, not a rules question, in rules-todo.md.
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function applyStatusToken(GameState $state, string $targetRole, string $token): array
    {
        $equipped = $state->wanderers[$targetRole]['equipped'];

        if ($token === 'shame_token' && self::countStatusTokens($equipped, 'shame_token') >= 3) {
            return [$state, []];
        }

        $emptySlot = array_search(null, $equipped, true);

        if ($emptySlot === false) {
            return [$state, []];
        }

        $equipped[$emptySlot] = $token;
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['equipped' => $equipped])]);

        $eventType = $token === 'shame_token' ? 'wanderer_shamed' : 'wanderer_scarred';

        return [$state, [['type' => $eventType, 'role_key' => $targetRole, 'payload' => ['slot' => $emptySlot]]]];
    }

    /**
     * Removes one token of $token from $targetRole's mat (whichever slot
     * it's in), if they have one. Used by Holy water (either type, caster's
     * choice) and Hands of forgiveness (both types, no choice needed).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function removeStatusToken(GameState $state, string $targetRole, string $token): array
    {
        $equipped = $state->wanderers[$targetRole]['equipped'];
        $slot = array_search($token, $equipped, true);

        if ($slot === false) {
            return [$state, []];
        }

        $equipped[$slot] = null;
        $state = $state->with(['wanderers' => $state->wanderersWith($targetRole, ['equipped' => $equipped])]);

        $eventType = $token === 'shame_token' ? 'wanderer_shame_removed' : 'wanderer_scar_removed';

        return [$state, [['type' => $eventType, 'role_key' => $targetRole, 'payload' => ['slot' => $slot]]]];
    }

    /**
     * @param  array<string, string|null>  $equipped
     */
    private static function countStatusTokens(array $equipped, string $token): int
    {
        return count(array_filter($equipped, fn ($value) => $value === $token));
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
