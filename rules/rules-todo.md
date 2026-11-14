# Rules TODOs

Ambiguities, gaps, and things to formalize in `rules.md`. I don't edit
`rules.md` — this is the list for you to work from. I remove items once
they're resolved and fold the resolution into my own understanding instead
(no need to keep a changelog here).

## Open

(none currently)

## Resolved (for my own reference, not standing asks)

- Rift in time: sheet replaced the undefined "Revelation card" wording
  entirely — now "Choose a face-up Golem Glyph (if there are any),
  discard it, and replace it with another Glyph of the same type."
  Implemented by reusing `addGlyphOfType()` (discard the chosen one,
  draw a fresh one of the same type).
- Prohibition of inscribing: sheet reworded to "If anyone (Wanderer or
  Golem) adds a Glyph to the Golem mat, they gain 1 Shame token" —
  confirms it's a standing rule while equipped by ANYONE, not a
  personal reaction, and applies to whoever actually triggers the add.
  Implemented inside `addGlyphOfType()` itself (now takes an optional
  `$actorRole`, threaded through every call site that has one) so it
  covers every existing Glyph-adding Relic in one place; Golem-
  triggered adds (no actorRole) and the Golem itself (no slot to hold
  a token) are correctly no-ops.
- Farmer's insight: sheet replaced "Spaces that produce 🌾" with "🌳
  have double effect for you" — 🌳 is Fruit tree's own emoji, not a
  Location-produces branch, so this doubles Fruit tree's existing
  "+1 action on entry" to +2 for this card's holder.
- Emperor's Timepiece: sheet reworded to "all 5 Relics that begin with
  'Emperor's __'" — a concrete, countable condition matching the
  `countEmperorsRelics()` helper already shared by Emperor's Diary/
  Robes/Knowledge. Implemented the GameState-level effects (validate,
  trash, +1 📜) in GameEngine; the actual role swap is a GameRole
  user_id<->role_key reassignment in GameController::swapRoles(),
  reacting to the emperor_timepiece_swap event GameEngine emits —
  GameState itself has no notion of "who controls which role."

- Silent hunting cloak: sheet fixed to "tap its next 2 untapped cards."
  Implemented as on-a-🐾-space + sharing the Golem's space, same shape
  as every other tap Relic.
- Poof: sheet fixed 13 → 12 Monolith Shards (the exact size of the
  fixed pool), so "all of them within 2 spaces" is now reachable.
  Implemented, including a new `golemSkipped` flag on GameState since
  nothing previously modeled "skip the Golem phase for the rest of the
  game."
- Jericho inscription: sheet reworded to define "opposite side" as the
  space continuing in the same direction from the caster through the
  Wall — confirms Walls stay a whole-cell fixture, no rework needed.
  Implemented.
- Past troubles: sheet fixed "face-down" → "discarded" Glyphs. Added a
  new `glyphDiscardPile` on GameState (Glyphs bumped off the 4 active
  slots were previously just lost — this was a real, if harmless-so-far,
  data-loss gap, not only a wording fix) and wired Past troubles to
  shuffle it together with the active Glyphs.
- Scapegoat's catharsis: sheet fixed 5 → 4 Dark Relics, matching the
  data. Implemented.
- Time Paradox: sheet clarified "play 2 additional cards" → "draw and
  resolve 2 additional Golem cards from the bottom of the Golem deck."
  This is mechanically different from the generic "extra cards this
  round" counter it was using (which just extended the normal
  top-down revealed-row loop) — added a dedicated bottom-of-deck draw
  path (`golemPlayFromBottom`) instead.
- A few other balance numbers landed in the same sync and were updated
  to match: Divine aura heals 3 (was 2), Blinding explosion/Immolation
  rags/Whip of Justice had their "gain 📜" thresholds reworded but
  unchanged in effect (those clauses are skipped anyway, per the
  honesty pattern). Rejuvenation gown, Invigoration, and Divine Poison
  Inversion also changed text but remain unimplemented — no behavior
  to fix since there wasn't any yet.

- Solo/duo/trio play: Wanderer count is always exactly 2 or 3 — there's no
  such thing as a solo Wanderer or 4+ Wanderers. Human player count is
  decoupled from role count; a single player can pilot multiple roles
  (solo play = one player piloting both/all Wanderers + Emperor).
- Action economy is always the printed 2-Wanderer/3-Wanderer table; no
  other case exists to define.
- Roles: 2–3 Wanderers (fixed), Emperor (1, or split by rotation among
  whoever's piloting Wanderers), optional Golem-pilot (only relevant at
  higher player counts, but usable any time 2+ Wanderers and Emperor
  coverage exist). Role assignment happens in pre-game setup; roles are
  decoupled from literal player count.
- Board is a hex-adjacency grid using rectangular card art (6 neighbors per
  space, offset/brick layout) — Carcassonne-style free placement anywhere
  adjacent to the existing map, gated by resource requirements. Locations
  never move once placed. No explicit max board size, but implicitly capped
  at 6 starting + 8 (one per round) = 14 total.
- Location cost requirements: a single neighboring space can satisfy
  multiple cost symbols at once. Roads connect production across their
  whole connected network (now in rules.md's Infrastructure section).
- `print: false` in the synced sheets is irrelevant to gameplay (an
  artifact of the designer's Figma plugin) — treat every record as live.
- UI should prefer resource/branch emoji over string names wherever
  possible.
- Relics with a populated `location` field are location-locked (printed
  location name on the physical card back) — when filling a newly built
  Location's Relic slots, location-locked Relics for that Location must be
  included first/always, before drawing the rest generically from the
  branch deck.
- Golem info modal needs: upcoming (revealed) Golem cards + active Glyphs.
  Discard pile not needed.
- Turn gating: almost everything requires it being your role's turn, with
  explicit exceptions — dropping/picking up a Relic, and now equipping one
  too, are always available to any Wanderer, any time, turn or not.
- Each player gets their own focused/private UI view, but needs an activity
  log / step-by-step replay of what other players and the Golem did on
  their turns (mirrors beast-master-web's event log + spotlight pattern).
- Infrastructure pool is an intentionally fixed supply, never replenished —
  confirmed via chat, not a simplification. Starts at the full physical
  component count (12/10/2/2) and that's it for the whole game.
- Win/loss thresholds, Golem tie-breaking (🦶 pathing = Emperor's call),
  and the Road resource-network rule are all now written into rules.md
  directly.
- Draw-back-up is to 5 (rules.md's number is the source of truth; the
  Emperor Mat image saying 6 was stale).
- Shame/Scar token placement: the affected Wanderer chooses which of their
  own slots gets blocked in normal (cooperative) play. In adversary mode
  (a human Golem-pilot), the Golem-pilot chooses instead.
- A Bound Relic that can't be forced into its slot (Scarred, or already
  holding a Bound Relic) is dropped face-up on the Location, same as any
  other found-but-unequipped Relic. Longer-term UX idea from this
  conversation, not yet built: a "here's what you found, here's where it
  could go, keep or drop?" prompt at the moment of discovery rather than
  leaving it to sit in `holding` indefinitely.
- Golem card tapping: "tapped" means flipped face-down; when resolution
  reaches a tapped card it does nothing and gets discarded. Engine support
  now exists (`draw_pile`/`revealed` carry `{key, tapped}`, see
  GameEngine::resolveOrSkipTappedCard/tapGolemDrawPile), proven via Whip of
  Justice. Not a rules question anymore — wiring up the remaining tap
  Relics (Blinding explosion, Defanging tool, Golem log, Silent hunting
  cloak, Peacemaker's spirit, Soothing Lullaby) is my own implementation
  backlog, not tracked here.
