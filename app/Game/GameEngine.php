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
            'search_location' => self::searchLocation($state, $actorRole, $move),
            'equip_relic' => self::equipRelic($state, $actorRole, $move),
            'drop_relic' => self::dropRelic($state, $actorRole, $move),
            'pickup_relic' => self::pickupRelic($state, $actorRole, $move),
            'use_relic' => self::useRelic($state, $actorRole, $move),
            'rest' => self::rest($state, $actorRole),
            'tuck_relic_at_bazaar' => self::tuckRelicAtBazaar($state, $actorRole, $move),
            'cleanse_at_purification_pools' => self::cleanseAtPurificationPools($state, $actorRole),
            'magnetic_mail_move' => self::magneticMailMove($state, $actorRole, $move),
            'telekinesis_move' => self::telekinesisMove($state, $actorRole, $move),
            'laborers_jacket_move_allies' => self::laborersJacketMoveAllies($state, $actorRole, $move),
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
            'tucked' => [],
        ]);

        $state = $state->with(['board' => $board, 'locationHand' => $hand, 'relicDecks' => $relicDecks]);

        $events = [
            ['type' => 'location_played', 'role_key' => $actorRole, 'payload' => ['location_key' => $locationKey, 'cell' => $cell]],
        ];

        // Scriptorium: "When building this: Move all Monolith Shards to
        // the Emperor's infrastructure pool."
        if ($locationKey === 'scriptorium') {
            $board = $state->board;
            $pool = $state->infrastructurePool;
            $returned = 0;

            foreach ($board as $key => $entry) {
                $index = array_search('monolith_shard', $entry['infrastructure'], true);

                while ($index !== false) {
                    $infrastructure = $board[$key]['infrastructure'];
                    unset($infrastructure[$index]);
                    $board[$key] = [...$board[$key], 'infrastructure' => array_values($infrastructure)];
                    $pool['monolith_shard']++;
                    $returned++;
                    $index = array_search('monolith_shard', $board[$key]['infrastructure'], true);
                }
            }

            $state = $state->with(['board' => $board, 'infrastructurePool' => $pool]);

            if ($returned > 0) {
                $events[] = ['type' => 'infrastructure_returned', 'role_key' => null, 'payload' => ['count' => $returned, 'infrastructure' => 'monolith_shard']];
            }
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
        ]);

        return [$state, [
            ['type' => 'infrastructure_placed', 'role_key' => $actorRole, 'payload' => ['infrastructure' => $type, 'cell' => $cell]],
        ]];
    }

    /**
     * Builder's gloves: "If you are on a space that produces ⚙️ during
     * the Emperor's phase, the Emperor may play +1 infrastructure
     * token." Any Wanderer equipping it on such a space raises the
     * normal 2-per-turn cap to 3.
     */
    private static function infrastructureCapThisTurn(GameState $state): int
    {
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
        // Creative alterations: "Your Relics can go into any slot, and
        // you can ignore Types" — bypasses the type match, but the
        // slot itself still has to be one of the 5 real equip slots.
        $ignoresTypes = in_array('creative-alterations', $wanderer['equipped'], true);

        if ($relic === null || ! array_key_exists($slot, $wanderer['equipped']) || (! $ignoresTypes && ! self::relicFitsSlot($relic->slot, $slot))) {
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

        $equipped = $wanderer['equipped'];
        $fromSlot = array_search($relicKey, $equipped, true);

        if ($fromSlot !== false) {
            // rules.md: a Bound Relic can never be voluntarily dropped
            // once equipped — only trashed via its own effect. It can
            // still be dropped face-up while merely held (not yet
            // equipped), same as any other found-but-unequipped Relic.
            if (RelicCatalog::find($relicKey)?->isBound === true) {
                throw new InvalidMoveException('Bound Relics cannot be dropped once equipped.');
            }

            $equipped[$fromSlot] = null;
        } elseif (in_array($relicKey, $wanderer['holding'], true)) {
            // handled below via holding diff
        } else {
            throw new InvalidMoveException('You do not have that Relic.');
        }

        $cellKey = Board::key($wanderer['cell']);
        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'relics' => [...$board[$cellKey]['relics'], $relicKey]];

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

        self::requireNotExhausted($wanderer);

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

        $hpBeforeDivineIcon = array_map(fn (array $w) => $w['hp'], $state->wanderers);

        [$state, $relicEvents, $trash] = self::resolveRelicAction($state, $actorRole, $baseKey, $relicKey, $move);
        $events = [...$events, ...$relicEvents];

        [$state, $divineIconEvents] = self::applyDivineIconBoost($state, $actorRole, $hpBeforeDivineIcon);
        $events = [...$events, ...$divineIconEvents];

        // Golem sanctuary: "When playing a 🌿 Relic in the Golem's
        // space, tuck the Relic here." Reuses the same HP-diff used for
        // Divine Icon to detect a 🌿 effect without special-casing
        // every heal Relic. Overrides the Relic's own trash clause —
        // it gets tucked here either way.
        $healedAnyone = (bool) array_filter(
            $state->wanderers,
            fn (array $w, string $r) => $w['hp'] > ($hpBeforeDivineIcon[$r] ?? $w['hp']),
            ARRAY_FILTER_USE_BOTH
        );
        $casterCellKey = Board::key($state->wanderers[$actorRole]['cell']);
        $onGolemSanctuary = $healedAnyone
            && $state->board[$casterCellKey]['location_key'] === 'golem-sanctuary'
            && self::golemSharesCellWith($state, $actorRole);

        if ($onGolemSanctuary && in_array($relicKey, $state->wanderers[$actorRole]['equipped'], true)) {
            $equipped = $state->wanderers[$actorRole]['equipped'];
            $equipped[array_search($relicKey, $equipped, true)] = null;
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['equipped' => $equipped])]);

            $board = $state->board;
            $board[$casterCellKey] = [...$board[$casterCellKey], 'tucked' => [...$board[$casterCellKey]['tucked'], $relicKey]];
            $state = $state->with(['board' => $board]);

            $events[] = ['type' => 'relic_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'cell' => $state->wanderers[$actorRole]['cell']]];
        } elseif ($trash) {
            $state = self::trashEquippedRelic($state, $actorRole, $relicKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey]];
        }

        return [$state, $events];
    }

    /**
     * Divine Icon: "All 🌿 Relics used in your space by you or your
     * Allies get +3." Rather than special-casing every heal Relic
     * individually, this diffs every Wanderer's HP across the whole
     * use_relic call and adds +3 more to any positive gain — which
     * only ever happens for a 🌿 effect in the first place, so the
     * diff alone is enough to find them. Only triggers if the caster
     * (not the target) shares a space with a Divine Icon holder,
     * matching "used in your space."
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

        $events = [];

        foreach ($state->wanderers as $role => $wanderer) {
            $gained = $wanderer['hp'] - ($hpBefore[$role] ?? $wanderer['hp']);

            if ($gained <= 0) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $wanderer['hp'] + 3);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
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
            'divine-robes' => [...self::relicDivineRobes($state, $actorRole), false],
            'divine-solidarity' => [...self::relicDivineSolidarity($state, $actorRole), false],
            'warlocks-robes' => [...self::relicWarlocksRobes($state, $actorRole, $move), false],
            'emperors-diary' => [...self::relicEmperorsDiary($state, $actorRole), false],
            'emperors-robes' => [...self::relicEmperorsRobes($state, $actorRole, $move), false],
            'an-insight-from-the-future' => self::relicInsightFromTheFuture($state, $move),
            'aural-inscription' => self::relicAuralInscription($state),
            'divine-chant' => self::relicDivineChant($state, $actorRole),
            'magistrates-robes' => [...self::relicMagistratesRobes($state, $actorRole, $move), false],
            'immolation-rags' => self::relicImmolationRags($state, $actorRole),
            'divine-cabalist-pyre' => self::relicDivineCabalistPyre($state, $actorRole, $move),
            'show-solidarity' => self::relicShowSolidarity($state, $actorRole, $move),
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
            'golem-log' => self::relicGolemLog($state, $actorRole, $move),
            'merchants-gloves' => [...self::relicMerchantsGloves($state, $actorRole, $move), false],
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
     * card. $move carries any extra choice a specific Event needs
     * (e.g. Epiphany's target cell) — empty when resolved via a bonus
     * search from another Relic's effect rather than the top-level
     * search_location move.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveEventRelic(GameState $state, string $actorRole, string $relicKey, array $move): array
    {
        return match ($relicKey) {
            'time-jump' => self::golemPlayCards($state, 1),
            'guilty-by-association', 'guilty-conscience' => self::applyStatusToken($state, $actorRole, 'shame_token'),
            'sharp-objects' => self::applyStatusToken($state, $actorRole, 'scar_token'),
            'incarceration' => self::relicIncarceration($state, $actorRole),
            'buoyant-spirit' => self::relicActionDelta($state, $actorRole, 2),
            'lost-in-thought' => self::relicActionDelta($state, $actorRole, -1),
            'time-spasm' => self::relicTimeSpasm($state),
            'epiphany' => self::relicEpiphany($state, $actorRole, $move),
            'golem-avarice' => self::relicGolemAvarice($state, $actorRole),
            'magic-explosion' => self::relicMagicExplosion($state, $actorRole),
            'a-rush-of-companionship' => self::relicRushOfCompanionship($state),
            'invigoration' => self::relicInvigoration($state, $actorRole, $move),
            default => [$state, []],
        };
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
     * choose." $move['cell'] picks which built, non-empty, non-caster
     * Location to pull a random face-down Relic from.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicEpiphany(GameState $state, string $actorRole, array $move): array
    {
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

        $moved = $state->board[$casterCellKey]['relics'];
        $board = $state->board;
        $board[$casterCellKey] = [...$board[$casterCellKey], 'relics' => []];
        $board[$golemCellKey] = [...$board[$golemCellKey], 'relics' => [...$board[$golemCellKey]['relics'], ...$moved]];
        $state = $state->with(['board' => $board]);

        return [$state, [['type' => 'relics_moved_to_golem', 'role_key' => null, 'payload' => ['count' => count($moved)]]]];
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

        $relics = $state->board[$casterCellKey]['relics'];
        $board = $state->board;
        $board[$casterCellKey] = [...$board[$casterCellKey], 'relics' => []];

        $landedKeys = [];
        foreach ($relics as $i => $relicKey) {
            $neighborKey = Board::key($neighbors[$i % count($neighbors)]);
            $board[$neighborKey] = [...$board[$neighborKey], 'relics' => [...$board[$neighborKey]['relics'], $relicKey]];
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
     * actions this round." The "may" is a real optional choice —
     * $move['take_invigoration'] must be explicitly truthy, since
     * defaulting to either yes or no would be putting words in the
     * player's mouth, unlike a plain unconditional effect.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function relicInvigoration(GameState $state, string $actorRole, array $move): array
    {
        if (($move['take_invigoration'] ?? false) !== true) {
            return [$state, []];
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
        $events = [['type' => 'golem_moved', 'role_key' => $actorRole, 'payload' => ['to' => $destination]]];

        [$state, $e] = self::moveDarkChainsWanderers($state, $golemCell, $destination);
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
     * Divine aura: "If you are on a space with a Monolith Shard, 🌿3 for
     * all Wanderers on or adjacent to your space."
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
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (! in_array(Board::key($cell), $cellKeys, true)) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 3);
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
     * you and your Ally." Heals every Wanderer sharing the caster's
     * space (including the caster) rather than requiring a single
     * chosen target, since the printed text doesn't single one out when
     * more than one Ally is present.
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

        $events = [];
        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) !== $casterCellKey) {
                continue;
            }

            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 4);
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
     * Relic you have equipped that starts with 'Emperor's __.'" Only
     * equipped Relics count here (unlike Emperor's Diary, which also
     * counts held ones) — that's what the card actually says.
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
        $state = $state->with(['locationDeck' => $deck, 'locationHand' => $hand]);

        return [$state, [['type' => 'location_drawn', 'role_key' => null, 'payload' => ['location_key' => $locationKey]]], true];
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
    private static function relicAuralInscription(GameState $state): array
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

        return [...self::addGlyphOfType($state, '🎨'), true];
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
                : self::countStatusTokens($state->wanderers[$fromRole]['equipped'], 'shame_token') > 0;

            $room = $toRole === 'golem'
                ? $state->golem['shame'] < 3
                : self::countStatusTokens($state->wanderers[$toRole]['equipped'], 'shame_token') < 3;

            if (! $available || ! $room) {
                break;
            }

            if ($fromRole === 'golem') {
                $state = $state->with(['golem' => $state->golemWith(['shame' => $state->golem['shame'] - 1])]);
            } else {
                [$state, $e] = self::removeStatusToken($state, $fromRole, 'shame_token');
                $events = [...$events, ...$e];
            }

            if ($toRole === 'golem') {
                $state = $state->with(['golem' => $state->golemWith(['shame' => $state->golem['shame'] + 1])]);
            } else {
                [$state, $e] = self::applyStatusToken($state, $toRole, 'shame_token');
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

        return [$state, $events, false];
    }

    /**
     * Divine Cabalist pyre: "Trash any number of face-up Relics on your
     * space, and trash this. 🌿 yourself and Allies on your space 2 for
     * each Relic you burned." $move['board_relic_keys'] picks which (any
     * number, including zero) from the caster's own cell's face-down...
     * actually face-up-once-found pile.
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

        $pile = $state->board[$cellKey]['relics'];
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
        $board[$cellKey] = [...$board[$cellKey], 'relics' => $pile];
        $state = $state->with(['board' => $board]);

        $events = array_map(
            fn (string $key) => ['type' => 'relic_burned', 'role_key' => null, 'payload' => ['relic_key' => $key, 'cell' => $casterCell]],
            $burned
        );

        $healAmount = 2 * count($burned);

        if ($healAmount > 0) {
            foreach (self::activeWanderers($state) as $role => $cell) {
                if (Board::key($cell) !== $cellKey) {
                    continue;
                }

                $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + $healAmount);
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
                $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
            }
        }

        return [$state, $events, true];
    }

    /**
     * Show solidarity: "Wanderers on the Golem's space may trash Relics
     * from their mats (you must trash this card as part of that). For
     * every 5 Relics trashed, trash 1 card from the Golem discard pile,
     * and gain 📜. Shuffle the Golem draw pile and discard pile."
     * $move['trashed'] is a list of {role, relic_key} for every
     * voluntary trash beyond Show solidarity's own mandatory one; each
     * entry must name an active Wanderer sharing the Golem's space with
     * that Relic actually equipped.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicShowSolidarity(GameState $state, string $actorRole, array $move): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

        $golemCell = $state->golem['cell'];
        $golemCellKey = $golemCell === null ? null : Board::key($golemCell);
        $requested = $move['trashed'] ?? [];
        $events = [];
        $trashedCount = 1; // Show solidarity itself, trashed by the normal post-dispatch flow.

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

        $groupsOf5 = intdiv($trashedCount, 5);

        if ($groupsOf5 > 0) {
            $discardPile = $state->golem['discard_pile'];
            $removed = array_splice($discardPile, 0, min($groupsOf5, count($discardPile)));
            $state = $state->with(['golem' => $state->golemWith(['discard_pile' => $discardPile])]);

            foreach ($removed as $cardKey) {
                $events[] = ['type' => 'golem_card_removed_from_discard', 'role_key' => null, 'payload' => ['card_key' => $cardKey]];
            }
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
     * Glyphs on its mat you choose, trash this, and gain 📜. Then, the
     * Golem plays 3 cards." $move['glyph_keys'] picks which (any
     * number, including zero) of the Golem's active Glyphs to discard
     * before it plays. Reuses golemPlayCards so "plays 3 cards" is
     * identical to 3 plays during a normal Golem phase — including
     * Double Down/Time Paradox if drawn.
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

        $state = $state->with(['golem' => $state->golemWith(['cell' => null]), 'golemSkipped' => true]);

        return [$state, [['type' => 'golem_removed', 'role_key' => null, 'payload' => []]], true];
    }

    /**
     * Scapegoat's catharsis: "If you have all 4 Relics with 'Dark ...'
     * names equipped: Allies lose 10 Health. Then, trash this, and all
     * Dark Relics. Gain 📜📜." There are exactly 4 Dark Relics in the
     * data (Dark chains, Dark limitations, Dark sacrifice, Dark sigil).
     *
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicScapegoatsCatharsis(GameState $state, string $actorRole): array
    {
        $darkKeys = ['dark-chains', 'dark-limitations', 'dark-sacrifice', 'dark-sigil'];
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
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => 10]];
        }

        foreach ($darkKeys as $darkKey) {
            $state = self::trashEquippedRelic($state, $actorRole, $darkKey);
            $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $darkKey]];
        }

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

            [$state, $events] = self::addGlyphOfType($state, '🎨');
            $events[] = ['type' => 'infrastructure_returned', 'role_key' => null, 'payload' => ['cell' => $wallCell]];

            return [$state, $events, true];
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

        return [...self::addGlyphOfType($state, '📖'), true];
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

        return [$state, $events, count($tuckedCards['defanging-tool']) >= 3];
    }

    /**
     * Golem log: "If you are in the Golem's space: look at the top 3
     * cards of its draw pile, then tuck one of them here. When there
     * are 3 cards tucked here, move them to the Golem discard pile,
     * trash this, and gain 📜. If you drop this Relic, return the
     * cards to its discard pile and lose your progress" (see
     * dropRelic). $move['card_key'] picks which of the revealed top 3.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: bool}
     */
    private static function relicGolemLog(GameState $state, string $actorRole, array $move): array
    {
        if (! self::golemSharesCellWith($state, $actorRole)) {
            return [$state, [], false];
        }

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

        $events = [[
            'type' => 'golem_draw_pile_revealed', 'role_key' => $actorRole,
            'payload' => ['card_keys' => array_map(fn (array $c) => $c['key'], $topThree)],
        ]];

        if ($pileIndex === null) {
            return [$state, $events, false];
        }

        unset($drawPile[$pileIndex]);
        $drawPile = array_values($drawPile);
        $state = $state->with(['golem' => $state->golemWith(['draw_pile' => $drawPile])]);

        $tuckedCards = $state->wanderers[$actorRole]['tucked_cards'];
        $tuckedCards['golem-log'] = [...($tuckedCards['golem-log'] ?? []), $chosenKey];
        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['tucked_cards' => $tuckedCards])]);
        $events[] = ['type' => 'card_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => 'golem-log', 'card_key' => $chosenKey]];

        if (count($tuckedCards['golem-log']) < 3) {
            return [$state, $events, false];
        }

        $golem = $state->golem;
        $golem['discard_pile'] = [...$golem['discard_pile'], ...$tuckedCards['golem-log']];
        $state = $state->with(['golem' => $golem]);

        return [$state, $events, true];
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

        $golemCellBefore = $state->golem['cell'];
        $state = $state->with(['golem' => $state->golemWith(['cell' => $casterCell])]);
        $events = [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $casterCell]]];

        if ($golemCellBefore !== null) {
            [$state, $e] = self::moveDarkChainsWanderers($state, $golemCellBefore, $casterCell);
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

        return $state->with([
            'wanderers' => $state->wanderersWith($actorRole, ['equipped' => $equipped]),
            'trashedRelics' => [...$state->trashedRelics, $relicKey],
        ]);
    }

    /**
     * Dark limitations: "Your Health can never exceed 5." Every heal
     * site caps at this instead of the usual 15.
     */
    private static function maxHpFor(GameState $state, string $role): int
    {
        $equipped = $state->wanderers[$role]['equipped'];

        // Cabalist's Gown: "your Health ... cannot rise above 5."
        return in_array('dark-limitations', $equipped, true) || in_array('cabalists-gown', $equipped, true) ? 5 : 15;
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

        $wandererOrder = RoleKeys::wanderers($state->wandererCount);
        $firstRole = $wandererOrder[0];
        $actionsPerTurn = self::turnStartActions($state, $firstRole);

        $wanderers = $state->wanderers;
        $wanderers[$firstRole] = [...$wanderers[$firstRole], 'actions_remaining' => $actionsPerTurn, 'move_blocked_this_turn' => false];

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

        self::requireNotExhausted($wanderer);

        if ($wanderer['move_blocked_this_turn']) {
            throw new InvalidMoveException('Incarceration: you cannot move for the rest of this turn.');
        }

        $direction = (string) ($move['direction'] ?? '');
        $to = Board::neighbor($wanderer['cell'], $direction);
        $toKey = Board::key($to);

        if (! array_key_exists($toKey, $state->board)) {
            throw new InvalidMoveException('Wanderers must always be on a Location.');
        }

        // Catacombs: "A Wanderer can only enter this space if they were
        // exhausted earlier in the game."
        if ($state->board[$toKey]['location_key'] === 'catacombs' && ! $wanderer['was_ever_exhausted']) {
            throw new InvalidMoveException('Catacombs: only a Wanderer who has been exhausted before may enter.');
        }

        // Exile: "Wanderers may only enter this space if they have 3
        // Shame tokens."
        if ($state->board[$toKey]['location_key'] === 'exile' && self::countStatusTokens($wanderer['equipped'], 'shame_token') < 3) {
            throw new InvalidMoveException('Exile: only a Wanderer with 3 Shame tokens may enter.');
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

        if (self::hasMonolithShardFor($state, $actorRole, $to) && in_array('rejuvenation-gown', $wanderer['equipped'], true)) {
            $hp = min(self::maxHpFor($state, $actorRole), $state->wanderers[$actorRole]['hp'] + 2);
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]];
        }

        // Beds of poison: "If a Wanderer enters this space for any
        // reason (even if they are just passing through while moving),
        // they lose 5 Health." Every step of movement is its own
        // moveWanderer call, so "passing through" is already covered.
        if ($state->board[$toKey]['location_key'] === 'beds-of-poison') {
            [$state, $damageEvents] = self::applyDamage($state, $actorRole, 5);
            $events = [...$events, ...$damageEvents];
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $actorRole, 'payload' => ['amount' => 5]];
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

                $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['cell' => $to])]);
                $events[] = ['type' => 'wanderer_moved', 'role_key' => $otherRole, 'payload' => ['to' => $to, 'free' => true]];

                if (self::hasMonolithShardFor($state, $otherRole, $to) && in_array('rejuvenation-gown', $state->wanderers[$otherRole]['equipped'], true)) {
                    $hp = min(self::maxHpFor($state, $otherRole), $state->wanderers[$otherRole]['hp'] + 2);
                    $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['hp' => $hp])]);
                    $events[] = ['type' => 'wanderer_healed', 'role_key' => $otherRole, 'payload' => ['hp' => $hp]];
                }

                if ($state->board[$toKey]['location_key'] === 'beds-of-poison') {
                    [$state, $damageEvents] = self::applyDamage($state, $otherRole, 5);
                    $events = [...$events, ...$damageEvents];
                    $events[] = ['type' => 'wanderer_damaged', 'role_key' => $otherRole, 'payload' => ['amount' => 5]];
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

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function endWandererTurn(GameState $state, string $actorRole): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

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
            $wanderers[$nextRole] = [...$wanderers[$nextRole], 'actions_remaining' => $actionsPerTurn, 'move_blocked_this_turn' => false];

            $state = $state->with(['wanderers' => $wanderers, 'activeWandererIndex' => $nextIndex]);

            return [$state, [
                ...$events,
                ['type' => 'wanderer_turn_started', 'role_key' => $nextRole, 'payload' => ['actions' => $actionsPerTurn]],
            ]];
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
     * Wanderer, +1 Action." Checked wherever a Wanderer's
     * actions_remaining is set back to the full per-turn amount.
     */
    private static function turnStartActions(GameState $state, string $role): int
    {
        $actionsPerTurn = $state->actionsPerWandererTurn();
        $cell = $state->wanderers[$role]['cell'];

        if ($cell === null || ! in_array('union-garb', $state->wanderers[$role]['equipped'], true)) {
            return $actionsPerTurn;
        }

        foreach (self::activeWanderers($state) as $otherRole => $otherCell) {
            if ($otherRole !== $role && Board::key($otherCell) === Board::key($cell)) {
                return $actionsPerTurn + 1;
            }
        }

        return $actionsPerTurn;
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

        if ($state->round >= self::ROUND_LIMIT) {
            $state = $state->with(['phase' => 'finished', 'activeWandererIndex' => null]);
            $events[] = ['type' => 'game_finished', 'role_key' => null, 'payload' => []];

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
                $state = $state->with(['phase' => 'finished', 'activeWandererIndex' => null]);
                $events[] = ['type' => 'game_finished', 'role_key' => null, 'payload' => ['reason' => 'exile']];

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
            'wanderers' => $wanderers,
        ]);
        $events[] = ['type' => 'round_started', 'role_key' => null, 'payload' => ['round' => $nextRound]];

        return [$state, $events];
    }

    /**
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function resolveGolemPhase(GameState $state): array
    {
        return self::golemPlayCards($state, $state->golemCardsThisRound());
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
    private static function golemPlayCards(GameState $state, int $count): array
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

            [$state, $cardEvents, $effects] = self::resolveOrSkipTappedCard($state, $cardEntry);
            $events = [...$events, ...$cardEvents];

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
                }
            }

            if ($effects['extra_from_bottom'] > 0) {
                [$state, $bottomEvents] = self::golemPlayFromBottom($state, $effects['extra_from_bottom']);
                $events = [...$events, ...$bottomEvents];
            }

            $state = self::refillGolemRevealed($state);
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
        }

        return [$state, $events];
    }

    /**
     * @param  array{key: string, tapped: bool}  $cardEntry
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: array{double_next: bool, extra_from_bottom: int}}
     */
    private static function resolveOrSkipTappedCard(GameState $state, array $cardEntry): array
    {
        $card = GolemCardCatalog::find($cardEntry['key']);
        $isAttackCard = $card !== null && str_contains($card->action, '👊');
        $hpBefore = $isAttackCard ? array_map(fn (array $w) => $w['hp'], $state->wanderers) : [];

        if ($cardEntry['tapped']) {
            [$state, $events, $effects] = [$state, [
                ['type' => 'golem_card_tapped', 'role_key' => null, 'payload' => ['card_key' => $cardEntry['key'], 'name' => $card?->name]],
            ], ['double_next' => false, 'extra_from_bottom' => 0]];
        } else {
            [$state, $events, $effects] = self::resolveGolemCard($state, $cardEntry['key']);
        }

        if ($isAttackCard) {
            [$state, $tuckEvents] = self::maybeTuckAtDefendersGuild($state, $cardEntry['key'], $hpBefore);
            $events = [...$events, ...$tuckEvents];
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
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>, 2: array{double_next: bool, extra_from_bottom: int}}
     */
    private static function resolveGolemCard(GameState $state, string $cardKey): array
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
                        [$state, $damageEvents] = self::applyDamage($state, $target, 3 * $shame, true);
                        $events = [...$events, ...$damageEvents];
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

                    [$state, $damageEvents] = self::applyDamage($state, $role, 2 * $shame, true);
                    $events = [...$events, ...$damageEvents];
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
                $effects['extra_from_bottom'] = 2;
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
        $events = [['type' => 'golem_moved', 'role_key' => null, 'payload' => ['to' => $destination]]];

        [$state, $e] = self::moveDarkChainsWanderers($state, $golemCell, $destination);
        $events = [...$events, ...$e];

        return [$state, $events];
    }

    /**
     * Dark chains: "If you are on the Golem's space when it moves, you
     * must move with it." Checked after every real Golem movement
     * (not removal/teleport-to-nowhere, like Poof).
     *
     * @param  array{0: int, 1: int}  $fromCell
     * @param  array{0: int, 1: int}  $toCell
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function moveDarkChainsWanderers(GameState $state, array $fromCell, array $toCell): array
    {
        $fromKey = Board::key($fromCell);
        $events = [];

        foreach (self::activeWanderers($state) as $role => $cell) {
            if (Board::key($cell) !== $fromKey || ! in_array('dark-chains', $state->wanderers[$role]['equipped'], true)) {
                continue;
            }

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $toCell])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $toCell, 'forced' => true]];
        }

        return [$state, $events];
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
            [$state, $damageEvents] = self::applyDamage($state, $role, 3, true);
            $events = [...$events, ...$damageEvents];
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
            [$state, $damageEvents] = self::applyDamage($state, $role, 5, true);
            $events = [...$events, ...$damageEvents];
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

            [$state, $damageEvents] = self::applyDamage($state, $role, $count, true);
            $events = [...$events, ...$damageEvents];
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

                [$state, $damageEvents] = self::applyDamage($state, $role, 2, true);
                $events = [...$events, ...$damageEvents];
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

            [$state, $damageEvents] = self::applyDamage($state, $role, 3, true);
            $events = [...$events, ...$damageEvents];
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
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $destination])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $destination, 'forced' => true]];
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
        $events[] = ['type' => 'wanderer_damaged', 'role_key' => $target, 'payload' => ['amount' => 5]];

        foreach ($allies as $role) {
            [$state, $damageEvents] = self::applyDamage($state, $role, 5, true);
            $events = [...$events, ...$damageEvents];
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
                [$state, $damageEvents] = self::applyDamage($state, $role, $damage, true);
                $events = [...$events, ...$damageEvents];
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
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => $damage]];
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
     * Returns any extra events those passive Relics caused, to merge
     * into the caller's own event list alongside its own
     * 'wanderer_damaged' event.
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

        $events = [];

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

                if ($holderHp === 0) {
                    $state = $state->with(['wanderers' => $state->wanderersWith($otherRole, ['was_ever_exhausted' => true])]);
                }
            }

            break;
        }

        $darkCount = count(array_filter(
            $equipped,
            fn (mixed $key) => is_string($key) && str_starts_with((string) RelicCatalog::find($key)?->name, 'Dark')
        ));
        $damage = max(0, $damage - $darkCount);

        $hpBefore = $state->wanderers[$role]['hp'];
        $hp = max(0, $hpBefore - $damage);

        if ($hp <= 0 && $hpBefore > 0 && in_array('cabalist-wraps', $equipped, true)) {
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => 1])]);
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

            // Catacombs: "A Wanderer can only enter this space if they
            // were exhausted earlier in the game." Cabalist wraps
            // explicitly prevents "becoming exhausted," so that branch
            // doesn't set this.
            if ($hp === 0) {
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['was_ever_exhausted' => true])]);
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
     * Inversion (heal 2), inscribe-7 (add a 🌑 Glyph, then trash), and
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
            $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 2);
            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
        }

        if (in_array('inscribe-7', $equipped, true)) {
            [$state, $e] = self::addGlyphOfType($state, '🌑');
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

            if (in_array('road', $entry['infrastructure'], true)
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
     * rules.md: "🌿: increase Health by 2" — the baseline Wanderer
     * action available even at 0 HP (where it's the only one available).
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

        $hp = min(self::maxHpFor($state, $actorRole), $wanderer['hp'] + 2);
        $wanderers = $state->wanderersWith($actorRole, [
            'hp' => $hp,
            'actions_remaining' => $wanderer['actions_remaining'] - 1,
        ]);
        $state = $state->with(['wanderers' => $wanderers]);

        return [$state, [['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]]]];
    }

    /**
     * Bazaar: "Action: Tuck a Relic from your mat here. Then, gain 1
     * action." Costs 1 action to take, then immediately refunds 1 —
     * net zero, matching the printed text exactly rather than treating
     * it as a free action.
     *
     * @param  array<string, mixed>  $move
     * @return array{0: GameState, 1: list<array{type: string, role_key: ?string, payload: array<string, mixed>}>}
     */
    private static function tuckRelicAtBazaar(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];

        if ($wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        self::requireNotExhausted($wanderer);

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
            // Net zero — the action spent tucking is immediately refunded.
        ]);
        $state = $state->with(['wanderers' => $wanderers]);

        $board = $state->board;
        $board[$cellKey] = [...$board[$cellKey], 'tucked' => [...$board[$cellKey]['tucked'], $relicKey]];
        $state = $state->with(['board' => $board]);

        return [$state, [['type' => 'relic_tucked', 'role_key' => $actorRole, 'payload' => ['relic_key' => $relicKey, 'cell' => $wanderer['cell']]]]];
    }

    /**
     * Purification pools: "Ongoing: If you begin your turn here, you
     * may remove Shame tokens, Scar tokens, and Bound Relics from your
     * mat." Modeled as a free action (no action cost, callable any
     * time during the Wanderer's own turn while on this space) rather
     * than strictly gating it to the literal first instant of the
     * turn, since there's no mid-phase pause to offer the choice right
     * then.
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

        $equipped = $wanderer['equipped'];
        $events = [];

        foreach ($equipped as $slot => $value) {
            if ($value === null) {
                continue;
            }

            if (self::isStatusToken($value)) {
                $equipped[$slot] = null;
                $events[] = ['type' => $value === 'shame_token' ? 'wanderer_shame_removed' : 'wanderer_scar_removed', 'role_key' => $actorRole, 'payload' => ['slot' => $slot]];
            } elseif (RelicCatalog::find($value)?->isBound === true) {
                $trashedKey = $value;
                $equipped[$slot] = null;
                $state = $state->with(['trashedRelics' => [...$state->trashedRelics, $trashedKey]]);
                $events[] = ['type' => 'relic_trashed', 'role_key' => $actorRole, 'payload' => ['relic_key' => $trashedKey]];
            }
        }

        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['equipped' => $equipped])]);

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

        $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['cell' => $ally['cell']])]);

        return [$state, [['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $ally['cell'], 'free' => true]]]];
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

        if ($wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        self::requireNotExhausted($wanderer);

        if ($wanderer['move_blocked_this_turn']) {
            throw new InvalidMoveException('Incarceration: you cannot move for the rest of this turn.');
        }

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

        if ($destinationLocationKey === 'catacombs' && ! $wanderer['was_ever_exhausted']) {
            throw new InvalidMoveException('Catacombs: only a Wanderer who has been exhausted before may enter.');
        }

        if ($destinationLocationKey === 'exile' && self::countStatusTokens($wanderer['equipped'], 'shame_token') < 3) {
            throw new InvalidMoveException('Exile: only a Wanderer with 3 Shame tokens may enter.');
        }

        $destinationInfrastructure = $state->board[$toKey]['infrastructure'];

        $wanderers = $state->wanderersWith($actorRole, [
            'cell' => $toCell,
            'actions_remaining' => $wanderer['actions_remaining'] - 1,
            'free_move_available' => in_array('road', $destinationInfrastructure, true),
        ]);
        $state = $state->with(['wanderers' => $wanderers]);

        $events = [['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $toCell, 'free' => false]]];

        if ($destinationLocationKey === 'beds-of-poison') {
            [$state, $damageEvents] = self::applyDamage($state, $actorRole, 5);
            $events = [...$events, ...$damageEvents];
            $events[] = ['type' => 'wanderer_damaged', 'role_key' => $actorRole, 'payload' => ['amount' => 5]];
        }

        if (self::hasMonolithShardFor($state, $actorRole, $toCell) && in_array('rejuvenation-gown', $wanderer['equipped'], true)) {
            $hp = min(self::maxHpFor($state, $actorRole), $state->wanderers[$actorRole]['hp'] + 2);
            $state = $state->with(['wanderers' => $state->wanderersWith($actorRole, ['hp' => $hp])]);
            $events[] = ['type' => 'wanderer_healed', 'role_key' => $actorRole, 'payload' => ['hp' => $hp]];
        }

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

            $state = $state->with(['wanderers' => $state->wanderersWith($role, ['cell' => $toCell])]);
            $events[] = ['type' => 'wanderer_moved', 'role_key' => $role, 'payload' => ['to' => $toCell, 'free' => true]];

            if (self::hasMonolithShardFor($state, $role, $toCell) && in_array('rejuvenation-gown', $state->wanderers[$role]['equipped'], true)) {
                $hp = min(self::maxHpFor($state, $role), $state->wanderers[$role]['hp'] + 2);
                $state = $state->with(['wanderers' => $state->wanderersWith($role, ['hp' => $hp])]);
                $events[] = ['type' => 'wanderer_healed', 'role_key' => $role, 'payload' => ['hp' => $hp]];
            }

            if ($state->board[$toKey]['location_key'] === 'beds-of-poison') {
                [$state, $damageEvents] = self::applyDamage($state, $role, 5);
                $events = [...$events, ...$damageEvents];
                $events[] = ['type' => 'wanderer_damaged', 'role_key' => $role, 'payload' => ['amount' => 5]];
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

        // Shamlessness: "Ignore all cards that tell you to gain Shame tokens."
        if ($token === 'shame_token' && in_array('shamlessness', $equipped, true)) {
            return [$state, []];
        }

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
