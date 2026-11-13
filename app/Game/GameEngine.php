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
 * Phase A+B+C scope: setup (choose starting cells for Wanderers and the
 * Golem), Emperor phase (play a Location — dealing its Relics automatically
 * — discard, redraw), Wanderer phase (move, search, equip/drop/pickup
 * Relics), and a Golem phase that actually resolves cards — see
 * resolveGolemCard() for exactly which of the 29 cards have real behavior
 * vs. are logged-but-not-executed. Relic *effects* are never executed,
 * only dealing/searching/equipping them. Infrastructure and scoring aren't
 * modeled yet.
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
            'end_emperor_phase' => self::endEmperorPhase($state, $actorRole, $move),
            'move_wanderer' => self::moveWanderer($state, $actorRole, $move),
            'search_location' => self::searchLocation($state, $actorRole),
            'equip_relic' => self::equipRelic($state, $actorRole, $move),
            'drop_relic' => self::dropRelic($state, $actorRole, $move),
            'pickup_relic' => self::pickupRelic($state, $actorRole, $move),
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
            if (! self::neighborProduces($state, $cell, $cost)) {
                throw new InvalidMoveException("Must be built adjacent to a space that produces {$cost}.");
            }
        }

        [$relics, $relicDecks] = RelicDealer::deal($state->relicDecks, $location);

        $hand = array_values(array_diff($state->locationHand, [$locationKey]));
        $board = $state->boardWith($cellKey, [
            'location_key' => $locationKey,
            'placed_round' => $state->round,
            'relics' => $relics,
        ]);

        $state = $state->with(['board' => $board, 'locationHand' => $hand, 'relicDecks' => $relicDecks]);

        return [$state, [
            ['type' => 'location_played', 'role_key' => $actorRole, 'payload' => ['location_key' => $locationKey, 'cell' => $cell]],
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

    private static function moveWanderer(GameState $state, string $actorRole, array $move): array
    {
        self::requireRole($state, $actorRole, $state->activeWandererRole(), 'wanderer');

        $wanderer = $state->wanderers[$actorRole];

        if ($wanderer['actions_remaining'] <= 0) {
            throw new InvalidMoveException('No actions remaining this turn.');
        }

        $direction = (string) ($move['direction'] ?? '');
        $to = Board::neighbor($wanderer['cell'], $direction);
        $toKey = Board::key($to);

        if (! array_key_exists($toKey, $state->board)) {
            throw new InvalidMoveException('Wanderers must always be on a Location.');
        }

        $wanderers = $state->wanderersWith($actorRole, [
            'cell' => $to,
            'actions_remaining' => $wanderer['actions_remaining'] - 1,
        ]);

        $state = $state->with(['wanderers' => $wanderers]);

        return [$state, [
            ['type' => 'wanderer_moved', 'role_key' => $actorRole, 'payload' => ['to' => $to]],
        ]];
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
        $state = $state->with(['round' => $nextRound, 'phase' => 'emperor', 'activeWandererIndex' => null]);
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

        $path = Board::shortestPath($golemCell, $state->wanderers[$targetRole]['cell'], $state->board);

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
            $distance = Board::distance($golemCell, $cell, $state->board);

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

    private static function neighborProduces(GameState $state, array $cell, string $branch): bool
    {
        foreach (Board::occupiedNeighbors($cell, $state->board) as $neighbor) {
            $entry = $state->board[Board::key($neighbor)];
            $location = LocationCatalog::find($entry['location_key']);

            if ($location?->produces === $branch) {
                return true;
            }
        }

        return false;
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
