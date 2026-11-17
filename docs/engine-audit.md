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

| Type | Glyph | Printed effect | Where it'd hook in |
|---|---|---|---|
| ⏳ | Paradoxical | After the Golem's first card each phase, each Wanderer may 🦶1 | `resolveGolemPhase` / `golemPlayCards` |
| ⏳ | Perplexing | Playing a Location lets Wanderers move there free | `playLocation` |
| ⏳ | Parallel | Once/round, skip a drawn card, draw the next instead | `golemPlayCards` card-draw loop |
| ⚖️ | Accused | Golem always +1 Shame (doesn't count toward max 3) | Wherever `golem['shame']` is read — needs a derived "effective shame" rather than raw field, or every read site changes |
| ⚖️ | Chained | -3🦶 | `golemMoveToward*` family (step budget) |
| ⚖️ | Penitent | Golem plays 1 fewer card per Shame it has | `golemCardsThisRound()` / round-start card count |
| ✨ | Enchanted | -2👊 if Golem is on a Shard space | `applyDamage` (parallel to `active_attack_reduction`) |
| ✨ | Productive | Emperor plays 3 Infrastructure/turn | `infrastructureCapThisTurn()` — same shape as Builder's gloves |
| 🌑 | Fearful | Tapped card → Golem moves away 1 | `resolveOrSkipTappedCard` |
| 🌑 | Revelatory | Moving, lose any Health for +1🦶/Health lost | `moveWanderer` (needs new move-time choice) |
| 🌑 | Smoldering | Trash non-Bound Relics anytime for 2 HP each, free | New move type (e.g. `trash_relic_for_health`) |
| 🌑 | Energizing | +1 Action if turn starts on Golem's space | `turnStartActions()` — same seam as Union Garb/Fruit tree |
| 🎨 | Buoyant | +2🦶 to all Wanderer movement | `moveWanderer` step cost |
| 🎨 | Contemplative | -1🦶 | `moveWanderer` step cost |
| 🎨 | Lilted | Play 1 fewer card per 🎶 Relic equipped anywhere | `golemCardsThisRound()` |
| 🐾 | Empathetic | Play 1 fewer card per Wanderer on Golem's space | `golemCardsThisRound()` |
| 🐾 | Focused | Golem can only ever target highest-HP Wanderer | `nearestWanderer`/`farthestWanderer`/`leastHpWanderer` targeting helpers |
| 👺 | Mobile | +1🦶 | movement step budget |
| 👺 | Legalistic | +1👊 per Shame the **Wanderer** has | `applyDamage` (damage dealt, not taken) |
| 👺 | Accusing | Golem 👊 that costs HP → Wanderer gains Shame | `applyDamage` |
| 👺 | Vindictive | Wanderer plays a 🌿 Relic → Golem 👊5 everyone on its space | hook into every heal-Relic resolution, or the Divine Icon HP-diff detector already used for Golem sanctuary |
| 👺 | Cruel | +1 to all 👊 | `applyDamage` |
| 👺 | Relentless | +2🦶 | movement step budget |
| 👺 | Tyrannical | Wanderers cannot equip 🌿 Relics | `equipRelic` |
| 👺 | Reactive | Wanderer enters Golem's space → it plays a card | `moveWanderer` (Golem-space entry already checked for other things) |
| 👺 | Adaptive | A damaging action's card goes to bottom of draw pile, not discard | card resolution pile management |
| 👺 | Destructive | Golem moving onto Infrastructure destroys it | Golem move helpers (`golemMoveToward*`) |
| 👺 | Barricading | Wanderers can't move onto Golem's space (reverse is fine) | `moveWanderer` |
| 🤝 | Outnumbered | -3👊 if target shares a space with another Wanderer | `applyDamage` |
| 🤝 | Neighborly | Ally may move in + use a free 🌿 Relic when someone loses HP | reactive hook off `applyDamage` |
| 🤝 | Congenial | 🌿 Relics +3 if used sharing a space with another Wanderer | heal-amount resolution (many call sites — see §3) |
| 📖 | Blessed | 🌿 Relics heal +2 | same heal-amount resolution problem as Congenial |
| 📖 | Forgiving | Every Golem card played → all Wanderers 🌿1 | `golemPlayCards` |
| 📖 | Merciful | -1👊 | `applyDamage` |
| 📖 | Miraculous | Becoming exhausted → immediately 🌿3 | `applyDamage`'s exhaustion branch |

Several of these (Legalistic, Cruel, Merciful, Enchanted, Outnumbered) are
all "modify incoming/outgoing 👊" and belong in **one** damage-modifier
pipeline in `applyDamage`/`golemAttackHere` rather than one-off patches —
see §3's recommendation.

**Recommendation:** don't try to land all 35 in one pass. Triage by how
often they'll actually come up (the always-on combat modifiers — Cruel,
Merciful, Legalistic, Enchanted, Outnumbered — will matter in nearly every
game; Barricading/Destructive/Reactive are situational; Focused and
Tyrannical are rare-but-high-impact when they do apply) and do a batch of
~5 at a time the same way Relic batches were done this session, each with
its own test file.

---

## 2. Other confirmed gameplay bugs found during this audit

- **Emperor's Knowledge applies without being equipped.** `moveWanderer`
  unconditionally calls `countEmperorsRelics(equipped)` and grants
  `intdiv(count, 2)` refunded actions to every move — counting *any*
  equipped Relic whose name starts with "Emperor's" (Diary, Robes,
  Timepiece, Knowledge itself). The printed effect belongs to Emperor's
  Knowledge specifically ("When moving, move an additional space for every
  2 Relics you have equipped that start with 'Emperor's __'"), but nothing
  checks that the Wanderer actually has *that* card equipped — a Wanderer
  holding only Emperor's Diary + Emperor's Robes already gets the bonus
  for free. Needs an `in_array('emperors-knowledge', $wanderer['equipped'])`
  gate before the `+=` in `moveWanderer` (app/Game/GameEngine.php:~3780).

- **Passive/Event Relic correctness is unverified past "the key appears
  somewhere in GameEngine.php."** I checked every Action Relic end-to-end
  (63/63 accounted for after this fix), but the 32 Passive and 14 Event
  Relics were only checked for "is this key referenced anywhere," which
  doesn't confirm the condition logic is *right* — just that someone wrote
  code mentioning it. Prohibition of inscribing passed this exact check
  for weeks before the Golem-attribution bug surfaced. Worth a dedicated
  pass re-reading each Passive Relic's printed text against its
  implementation, the way the Inscribe family just got.

---

## 3. Structural / architectural debt

### 3.1 `GameEngine` is a single 6,300-line, 166-method static class

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

**Recommendation:** nothing fixes this without a larger rework (e.g. each
Relic owns a small struct — `{ condition: fn, resolve: fn, trashIfSuccess:
bool }` — looked up by key once, used for both the check and the effect).
Given 100+ Relics already written the old way, this is a "next big
refactor" candidate rather than a quick pass — but worth doing before the
Glyph batch above adds another 30-some conditions in the same scattered
style.

### 3.3 Catalog DTOs have a `toArray()` nobody calls

`Location`, `Relic`, `Glyph`, `GolemCard` each define their own
`toArray()`. `GameController` ignores all four and hand-rolls an
equivalent array shape in `locationCatalog()` / `relicCatalog()` /
`golemCardCatalog()` / `glyphCatalog()` instead (lines 199–252). The two
versions have already drifted once (the DTOs don't expose `isStarter` /
`hasShard` / `hasTree`, and the controller methods don't either — same
gap, kept in sync by luck, not by anything structural). Every future field
added to a DTO (like `isAction`, added this session) has to be
independently remembered in the controller too. Low risk, pure toil —
either delete the DTOs' `toArray()` and accept the controller is the one
source of truth, or delete the controller's versions and call
`$relic->toArray()` directly (filtering/renaming only where the frontend
genuinely needs something different).

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
line here *and* in `GameSetup::create()` in the same commit. This has
already been gotten wrong once in production (game CYTGGM) and caught
retroactively. The discipline note in the docblock is the only guard
rail. A small test asserting "every key GameSetup::create() produces
has a corresponding normalize() default" would turn "remember to do this"
into "CI tells you if you forgot."

---

## 4. Testing gaps

- **Zero frontend tests.** No Vitest/Jest/RTL/Playwright anywhere in the
  repo (`package.json` has no test script at all). Every dialog,
  highlight, polling flow, and move-submission handler built this session
  was validated only by `tsc` (types) and manual reasoning — never
  actually rendered or exercised. `tsc` catches type mismatches, not "the
  button does the wrong thing" or "the dialog never closes."
- **CI runs the Pest suite exactly once per push** (`composer ci:check` →
  `@test` → `php artisan test`, no `--repeat`, no seed variation). Several
  bugs this session were only ever caught by a human manually running the
  suite 20-30 times in a row, because `GameSetup::create()`'s random
  starting-Location draw (and Relic/Golem-card deck shuffles) means a test
  can pass on one random seed and fail on another. A single CI run can get
  lucky. Cheapest fix: add a CI step (or a Composer script) that runs
  `php artisan test` N times, or seed PHP's RNG per-test-run and rotate
  the seed in CI so failures are at least reproducible.
- **Passive/Event Relic test coverage is uneven** — some have dedicated
  tests (Cabalist's Gown, Divine Poison Inversion), many don't. No
  systematic "one test per Relic" checklist exists to point at and say
  "this one's unverified."

---

## 5. Performance notes

Nothing here is urgent at this game's scale (2-3 Wanderers, ≤14 board
cells, polling every 2s) — flagging for awareness, not because anything is
visibly slow:

- **Catalog JSON is re-read and re-parsed from disk on every request.**
  `LocationCatalog::all()` etc. cache in a `static` property, but that
  cache dies with the PHP-FPM request — so every poll (every 2s, times
  however many players are watching) re-reads and re-`json_decode`s
  ~80KB across 4 files just to build the catalog payload, even though that
  data never changes between deploys. `Cache::rememberForever()` (even the
  `array` or file cache driver) would turn this into a one-time cost per
  deploy instead of per-request. Not a problem yet; would matter if this
  ever hosted many concurrent games.
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

## Suggested priority order

1. **Glyph batch 1** — the always-on combat modifiers (Cruel, Merciful,
   Enchanted, Legalistic, Outnumbered) plus Accused, since they'll be felt
   in nearly every game and share one damage-pipeline seam.
2. **Emperor's Knowledge gate fix** — one-line, high-value, currently
   giving away a bonus for free.
3. **CI stability-loop step** — cheap, prevents re-discovering flaky
   tests by hand every session.
4. **Glyph batches 2-4** — movement modifiers, card-count modifiers,
   reactive/targeting Glyphs, roughly in the table order above.
5. Everything in §3 (structural refactors) — do opportunistically, not as
   a dedicated pass; each is low-urgency and higher-risk-of-regression
   than the gameplay gaps above.

No open questions for the designer — everything above is either an
implementation gap (effect exists in rules.md/the sheet, code doesn't do
it) or a code-only concern (nothing to ask about).
