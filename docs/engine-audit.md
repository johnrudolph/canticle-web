# Canticle engine audit — 2026-11-17

Self-review of the game engine after the Phase A→D build + several sessions
of production bug-fixing. Scope: `app/Game/*`, `app/Game/Catalog/*`,
`app/Http/Controllers/Game*Controller.php`, and the frontend surface that
consumes them. Goal: inventory tech debt, consistency gaps, and — the
biggest ask — **unimplemented game rules**, so "found a real gap" stops
being a surprise during play-testing.

This is a findings doc, not a plan. Nothing here has been fixed yet except
where noted. Severity is my own judgment, not the designer's.

---

## 1. The headline finding: Glyphs are almost entirely unimplemented

**35 of 36 Glyphs have zero gameplay effect.** Only **Inverted** (fixed this
session) does anything. Every other Glyph is tracked purely as data —
added to the Golem's mat, discarded, shuffled, counted by Pen's scoring —
but none of them modify combat, movement, card draw, or anything else the
way their printed text says they do.

This is by far the largest gap in the project. Glyphs are a core modifier
layer (rules.md: "Glyphs are passive abilities that modify the Golem") and
right now they're decoration — the Golem card draw/attack/move pipeline
never once checks `$state->golem['glyphs']` for anything except Inverted.

Full list, by type, with what's missing:

| Type | Glyph         | Printed effect                                                    | Where it'd hook in                                                                                                      |
| ---- | ------------- | ----------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| ⏳   | Paradoxical   | After the Golem's first card each phase, each Wanderer may 🦶1    | `resolveGolemPhase` / `golemPlayCards`                                                                                  |
| ⏳   | Perplexing    | Playing a Location lets Wanderers move there free                 | `playLocation`                                                                                                          |
| ⏳   | Parallel      | Once/round, skip a drawn card, draw the next instead              | `golemPlayCards` card-draw loop                                                                                         |
| ⚖️   | Accused       | Golem always +1 Shame (doesn't count toward max 3)                | Wherever `golem['shame']` is read — needs a derived "effective shame" rather than raw field, or every read site changes |
| ⚖️   | Chained       | -3🦶                                                              | `golemMoveToward*` family (step budget)                                                                                 |
| ⚖️   | Penitent      | Golem plays 1 fewer card per Shame it has                         | `golemCardsThisRound()` / round-start card count                                                                        |
| ✨   | Enchanted     | -2👊 if Golem is on a Shard space                                 | `applyDamage` (parallel to `active_attack_reduction`)                                                                   |
| ✨   | Productive    | Emperor plays 3 Infrastructure/turn                               | `infrastructureCapThisTurn()` — same shape as Builder's gloves                                                          |
| 🌑   | Fearful       | Tapped card → Golem moves away 1                                  | `resolveOrSkipTappedCard`                                                                                               |
| 🌑   | Revelatory    | Moving, lose any Health for +1🦶/Health lost                      | `moveWanderer` (needs new move-time choice)                                                                             |
| 🌑   | Smoldering    | Trash non-Bound Relics anytime for 2 HP each, free                | New move type (e.g. `trash_relic_for_health`)                                                                           |
| 🌑   | Energizing    | +1 Action if turn starts on Golem's space                         | `turnStartActions()` — same seam as Union Garb/Fruit tree                                                               |
| 🎨   | Buoyant       | +2🦶 to all Wanderer movement                                     | `moveWanderer` step cost                                                                                                |
| 🎨   | Contemplative | -1🦶                                                              | `moveWanderer` step cost                                                                                                |
| 🎨   | Lilted        | Play 1 fewer card per 🎶 Relic equipped anywhere                  | `golemCardsThisRound()`                                                                                                 |
| 🐾   | Empathetic    | Play 1 fewer card per Wanderer on Golem's space                   | `golemCardsThisRound()`                                                                                                 |
| 🐾   | Focused       | Golem can only ever target highest-HP Wanderer                    | `nearestWanderer`/`farthestWanderer`/`leastHpWanderer` targeting helpers                                                |
| 👺   | Mobile        | +1🦶                                                              | movement step budget                                                                                                    |
| 👺   | Legalistic    | +1👊 per Shame the **Wanderer** has                               | `applyDamage` (damage dealt, not taken)                                                                                 |
| 👺   | Accusing      | Golem 👊 that costs HP → Wanderer gains Shame                     | `applyDamage`                                                                                                           |
| 👺   | Vindictive    | Wanderer plays a 🌿 Relic → Golem 👊5 everyone on its space       | hook into every heal-Relic resolution, or the Divine Icon HP-diff detector already used for Golem sanctuary             |
| 👺   | Cruel         | +1 to all 👊                                                      | `applyDamage`                                                                                                           |
| 👺   | Relentless    | +2🦶                                                              | movement step budget                                                                                                    |
| 👺   | Tyrannical    | Wanderers cannot equip 🌿 Relics                                  | `equipRelic`                                                                                                            |
| 👺   | Reactive      | Wanderer enters Golem's space → it plays a card                   | `moveWanderer` (Golem-space entry already checked for other things)                                                     |
| 👺   | Adaptive      | A damaging action's card goes to bottom of draw pile, not discard | card resolution pile management                                                                                         |
| 👺   | Destructive   | Golem moving onto Infrastructure destroys it                      | Golem move helpers (`golemMoveToward*`)                                                                                 |
| 👺   | Barricading   | Wanderers can't move onto Golem's space (reverse is fine)         | `moveWanderer`                                                                                                          |
| 🤝   | Outnumbered   | -3👊 if target shares a space with another Wanderer               | `applyDamage`                                                                                                           |
| 🤝   | Neighborly    | Ally may move in + use a free 🌿 Relic when someone loses HP      | reactive hook off `applyDamage`                                                                                         |
| 🤝   | Congenial     | 🌿 Relics +3 if used sharing a space with another Wanderer        | heal-amount resolution (many call sites — see §3)                                                                       |
| 📖   | Blessed       | 🌿 Relics heal +2                                                 | same heal-amount resolution problem as Congenial                                                                        |
| 📖   | Forgiving     | Every Golem card played → all Wanderers 🌿1                       | `golemPlayCards`                                                                                                        |
| 📖   | Merciful      | -1👊                                                              | `applyDamage`                                                                                                           |
| 📖   | Miraculous    | Becoming exhausted → immediately 🌿3                              | `applyDamage`'s exhaustion branch                                                                                       |

**Update, 2026-11-17: 36 of 36 now implemented.** All combat/movement/
card-count/targeting/reactive/heal Glyphs from the table above landed —
see `tests/Unit/Game/GlyphEffectsTest.php` for coverage of each. The last
4 (Neighborly, Paradoxical, Perplexing, Parallel) were originally flagged
below as deliberately deferred because the existing architecture had no
clean seam for the choice each requires — each ended up needing a genuine
new seam rather than being forced into the wrong shape:

- **Neighborly** (🤝) — new `neighborly_assist` move type: an adjacent
  Ally can move in for free and use one of their own 🌿 Relics, targeted
  at the Wanderer who lost HP (`neighborlyAssist()`).
- **Paradoxical** (⏳) — rather than granting `+1 actions_remaining`
  immediately (a no-op, since the next turn's `turnStartActions()`
  overwrites it before anyone could spend it), it now banks a
  `pending_bonus_actions` field on each Wanderer, added into their next
  turn's action count and reset afterward (`resolveGolemPhase()`,
  `turnStartActions()`).
- **Perplexing** (⏳) — `GameState::$locationJustBuiltCell` records where
  a Location was just played; a new `move_to_new_location` move type lets
  Wanderers move there for free before the field is cleared.
- **Parallel** (⏳) — new `skip_golem_card` move type + a
  `parallelUsedThisRound` flag; discards the revealed card unresolved and
  draws the next one in its place, once per round.

---

## 2. Other confirmed gameplay bugs found during this audit

- **Fixed, 2026-11-17: Emperor's Knowledge applied without being
  equipped.** `moveWanderer`
  unconditionally called `countEmperorsRelics(equipped)` and granted
  `intdiv(count, 2)` refunded actions to every move — counting _any_
  equipped Relic whose name starts with "Emperor's" (Diary, Robes,
  Timepiece, Knowledge itself). The printed effect belongs to Emperor's
  Knowledge specifically ("When moving, move an additional space for every
  2 Relics you have equipped that start with 'Emperor's __'"), but nothing
  checks that the Wanderer actually has _that_ card equipped — a Wanderer
  holding only Emperor's Diary + Emperor's Robes already gets the bonus
  for free. Needs an `in_array('emperors-knowledge', $wanderer['equipped'])`
  gate before the `+=` in `moveWanderer` (app/Game/GameEngine.php:~3780).

- **Passive/Event Relic correctness is unverified past "the key appears
  somewhere in GameEngine.php."** I checked every Action Relic end-to-end
  (63/63 accounted for after this fix), but the 32 Passive and 14 Event
  Relics were only checked for "is this key referenced anywhere," which
  doesn't confirm the condition logic is _right_ — just that someone wrote
  code mentioning it. Prohibition of inscribing passed this exact check
  for weeks before the Golem-attribution bug surfaced. Worth a dedicated
  pass re-reading each Passive Relic's printed text against its
  implementation, the way the Inscribe family just got.

---

## 3. Structural / architectural debt

### 3.1 `GameEngine` is a single ~7,000-line, ~190-method static class

**Status, 2026-11-17: deliberately left as-is.** Considered splitting
along the seams below via PHP traits (mechanically safe — traits compile
into the consuming class's own scope, so `self::`/`private`/`static`
semantics don't change at all). Decided against doing it in this pass:
it's a pure file-organization change with zero functional payoff, against
a non-trivial chance of a copy-paste slip across ~190 methods (a wrong cut,
a missed `use App\Game\Catalog\...` import in a new trait file, etc.) that
PHPStan/tests would catch but that still costs a debugging detour for no
gameplay benefit. The growth since this note was first written (166 → 190
methods, from the Glyph batch) makes the case for splitting stronger, not
weaker — still worth doing, just as its own deliberate, carefully-verified
pass rather than bundled into a session already touching this file
constantly.

Original note (still accurate) on where the seams are:

Every rule in the game — setup, movement, all ~100 Relics, all 29 Golem
cards, scoring, Glyph bookkeeping — lives in one file as `private static`
methods on one class. It's internally organized reasonably well (relics
roughly grouped, Golem card resolution grouped, helpers at the bottom), but
there's no enforced boundary: a change to Relic resolution can silently
read Golem internals, and vice versa, because everything shares one scope.

This hasn't caused a bug yet that I can point to, but it's the reason
"where does X live" always requires a grep rather than a folder. If this
keeps growing (and Glyphs alone will add 20-30 more methods), I'd split
along the existing natural seams before it does:

- `GameEngine` → dispatch only (`apply()`, auth checks)
- `RelicResolver` (or similar) → the ~100 relic-specific private methods
- `GolemResolver` → card resolution + Golem movement/targeting helpers
- `MovementRules` → `moveWanderer`, Road/Fruit tree/Catacombs/Exile, the
  various relic-granted-movement variants (`relicMove`, `telekinesisMove`,
  `magneticMailMove`, `laborersJacketMoveAllies`)
- Keep `GameEngine::apply()` as the single public entry point so nothing
  about the external contract changes.

Not urgent — PHP doesn't punish a big class the way it punishes tangled
runtime coupling, and this one's coupling is actually pretty clean. But at
166 methods it's past the point where a new contributor (or me, three
months from now) can hold the whole file's shape in their head.

### 3.2 Every Relic condition is expressed in up to three separate places

The "pre-check so a doomed action never spends itself" pattern — this
session's main defensive architecture — requires writing the same logical
condition **three times**:

1. `resolveRelicAction` (or the Inscribe/Pray/etc.-specific dispatcher) —
   the actual gate before the effect runs
2. `relicUnmetConditionReason()` (or `inscribeUnmetReason()`) — the
   backend's pre-check, thrown before the action is spent
3. The frontend's disabled-button state, driven by #2's string output

These are **not** generated from one source — they're three hand-written
copies of the same boolean logic, and nothing fails loudly if they drift.
This is the exact root cause of nearly every "oh that's a real gap" bug
found this session: Pray, Inscribe-6, Guide rope into Catacombs, the
Infrastructure cap text — every one of them was a case where the real
effect-resolution code had a condition that the pre-check layer either
didn't have at all, or had with different logic. The pattern itself (fail
fast, tell the player why) is right; the lack of a single source of truth
for "what is this Relic's condition" is the debt.

**Mitigated, 2026-11-17** (lighter than the full rework below, which is
still the real fix): added
`tests/Unit/Game/RelicConditionChokepointTest.php` — extracts every
match-arm key from `relicUnmetConditionReason()`/`inscribeUnmetReason()`
via reflection and asserts each one still has a matching case in
`resolveRelicAction()`/`resolveInscribe()`. This only catches "a condition
got renamed/removed on one side and not the other" (what actually caused
most of the bugs listed above), not "the two conditions' _logic_ silently
diverged while both keys still exist" — the full rework below is the only
real fix for that half of the problem. Didn't attempt the full rework
blind across 100+ Relics in one pass; the drift-detector is the
proportionate mitigation for now.

**Recommendation (still open):** nothing fully fixes this without a
larger rework (e.g. each Relic owns a small struct — `{ condition: fn,
resolve: fn, trashIfSuccess: bool }` — looked up by key once, used for
both the check and the effect). Given 100+ Relics already written the old
way, this is a "next big refactor" candidate rather than a quick pass.

### 3.3 Catalog DTOs have a `toArray()` nobody calls

**Fixed, 2026-11-17.** `GameController`'s `locationCatalog()` /
`relicCatalog()` / `golemCardCatalog()` / `glyphCatalog()` now call
`->map(fn ($x) => $x->toArray())` instead of hand-rolling an equivalent
array shape; `Glyph`/`GolemCard` gained `toArray()` (previously had
none), and `Location`/`Relic`'s existing `toArray()` return types were
tightened to specific shapes so PHPStan catches the next drift instead of
silently accepting `array<string, mixed>`. One source of truth for each
DTO's wire shape now, not two hand-maintained copies.

### 3.4 Catalog keys are positional slugs with disambiguating suffixes

`RelicCatalog`/`GlyphCatalog` slugify each sheet row's name and append
`-2`, `-3`, ... for repeats, **in sheet row order**. "Inscribe" is 12 rows
→ `inscribe` through `inscribe-12`; the mapping from "which physical card"
to "which key" depends entirely on row order in the Google Sheet. If the
designer ever reorders rows (moves Inscribe #6 above #5, say), every
already-persisted game with `inscribe-5` equipped now points at a
different card's condition and effect text retroactively — silently, with
no error. This hasn't bitten anyone yet because the sheet has been
append-only so far, but it's a structural fragility worth knowing about
before assuming key stability. A `key` column in the sheet itself (stable,
designer-assigned) would remove the risk entirely.

### 3.5 `GameStateRepository::normalize()` is an append-only patch list

Documented and deliberate (good) — but it's manually hand-written per
field, and every future field addition depends on remembering to add a
line here _and_ in `GameSetup::create()` in the same commit. This has
already been gotten wrong once in production (game CYTGGM) and caught
retroactively. The discipline note in the docblock is the only guard
rail.

**Partially mitigated, 2026-11-17:** added a regression test
(`GameStateRepositoryTest.php`, "normalize() backfills every field it
currently promises to") that hardcodes every field `normalize()`
currently backfills and asserts an old-shaped persisted blob missing all
of them still loads correctly. This pins down _today's_ contract — any
accidental removal of a backfill line fails loudly — but it's still a
hand-maintained list, not auto-derived from `GameSetup::create()`'s
output. A fully automatic version was attempted and abandoned (see commit
history): there's no way to tell "optional field needing a default" from
"core required field with none by design" just by diffing against
`GameSetup`'s output, so the auto-derivation approach is fundamentally
unsound, not just unbuilt. The real guard rail is still "remember to add
a line here and in the new test in the same commit."

---

## 4. Testing gaps

- **Zero frontend tests.** No Vitest/Jest/RTL/Playwright anywhere in the
  repo (`package.json` has no test script at all). Every dialog,
  highlight, polling flow, and move-submission handler built this session
  was validated only by `tsc` (types) and manual reasoning — never
  actually rendered or exercised. `tsc` catches type mismatches, not "the
  button does the wrong thing" or "the dialog never closes."
- **Fixed, 2026-11-17: CI now runs the Pest suite 10x per push.** New
  `composer test:stability` script loops `php artisan test` 9 additional
  times, wired into `ci:check` after `@test`. Several Glyph tests this
  session were only ever caught by a human manually running the suite
  20-30 times, because `GameSetup::create()`'s random starting-Location
  draw (and Relic/Golem-card deck shuffles) means a test can pass on one
  random seed and fail on another — this is now automatic going forward
  instead of a manual habit to remember.
- **Passive/Event Relic test coverage is uneven** — some have dedicated
  tests (Cabalist's Gown, Divine Poison Inversion), many don't. No
  systematic "one test per Relic" checklist exists to point at and say
  "this one's unverified."

---

## 5. Performance notes

Nothing here is urgent at this game's scale (2-3 Wanderers, ≤14 board
cells, polling every 2s) — flagging for awareness, not because anything is
visibly slow:

- **Fixed, 2026-11-17: catalog JSON is now cached across requests.** New
  `CatalogFile::readJson()` wraps each catalog's file read in
  `Cache::rememberForever()`, keyed by the file's own `mtime` — so a
  `canticle:sync-cards` re-sync (which changes `mtime`) automatically
  invalidates the cache with no manual bust step, while every request
  between syncs reads from cache instead of re-parsing ~80KB of JSON
  across 4 files.
- Everything else (`activeWanderers`, `effectiveProduces`,
  `roadNetworkProduces`'s BFS, the various "find nearest X" Golem
  targeting helpers) is O(small board × small Wanderer count) — fine at
  this scale, not worth touching.

---

## 6. Smaller consistency notes (not bugs, just friction)

- Some relic-condition helpers return `bool` (`jerichoInscriptionReady`,
  `auralInscriptionReady`, `inscribePrisonJudgmentReady`), others return
  `?string` reasons (`relicUnmetConditionReason`), and the two styles get
  bridged with ternaries at each call site. Consistent, but another small
  tax paid by §3.2's lack of a single condition representation.
- `withDamagedEvent()` exists specifically to avoid logging a
  contradictory "damaged" line after Inverted's heal — a narrow patch for
  one Glyph. Once more damage-modifying Glyphs land (§1's Cruel/Merciful/
  Enchanted/Legalistic/Outnumbered), damage resolution should probably
  become "compute final amount and direction once, then log once,"
  rather than accumulating more `withDamagedEvent`-style patches per
  modifier.

---

## 7. UX: the Golem's turn was a black box

Added, 2026-11-17 — not originally in this audit, raised during play-
testing: during the Golem's phase, players had no visibility into what
each card actually did as it resolved — damage, movement, Glyph
modifiers, and reactive effects all happened invisibly between polls.

Fixed with a **Golem Turn Spotlight**: ending a Wanderer turn that
triggers Golem cards now opens a modal that steps through each card one
at a time — full printed text, a plain-English line per effect it caused
(reusing `ActivityLog`'s own `describe()` so wording never drifts from
the scrolling log), and the Golem's currently-active Glyphs for context.
The board (Golem's displayed cell) and each Wanderer's displayed HP stay
in sync with wherever the player has clicked to in the sequence, not the
final end-of-phase state, via `resources/js/lib/golem-turn-spotlight.ts`
replaying events onto a snapshot per segment.

Deliberately scoped lighter than the original ask in one place: true
causal "this specific effect happened _because of_ Glyph X" attribution
isn't tracked in event payloads, so the spotlight shows "here are the
Golem's active Glyphs" as context per card rather than annotating
individual effect lines with which Glyph caused them — that would need a
broader event-payload change (tagging each event with the Glyph(s) that
modified it) not attempted in this pass.

---

## Suggested priority order

Everything originally listed here is done. Remaining open items, roughly
in order of value:

1. **§3.2's full rework** (one source of truth per Relic condition,
   replacing the triplicated hand-written logic) — the drift-detector
   test added this pass only catches renames, not logic divergence
   between two still-existing conditions. Worth doing before the Relic
   count grows further.
2. **§3.1's trait split** — purely organizational, safe in principle
   (traits don't change runtime semantics), explicitly deferred to its
   own dedicated, carefully-verified pass rather than bundled in.
3. **§3.4** — a stable `key` column in the Google Sheet itself, removing
   the positional-slug fragility. Needs a sheet-side change outside this
   codebase's control; flagging for the designer, not something to fix
   unilaterally.
4. **§4's frontend test suite** — still zero Vitest/RTL/Playwright
   coverage; every dialog and move-submission handler built across this
   whole audit cycle was validated only by `tsc` + manual reasoning +
   (where feasible) a dev-server click-through, never a real automated
   render.
5. **The Golem Turn Spotlight's Glyph-attribution gap** (§7) — would need
   a broader event-payload change to tag individual effects with the
   Glyph that caused them, rather than just listing active Glyphs
   per-card.

No open questions for the designer except §3.4's sheet-side `key` column
suggestion — everything else above is either done or a code-only concern.
