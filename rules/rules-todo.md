# Rules TODOs

Ambiguities, gaps, and things to formalize in `rules.md`. I don't edit
`rules.md` — this is the list for you to work from. I remove items once
they're resolved and fold the resolution into my own understanding instead
(no need to keep a changelog here).

## Open

1. **Rift in time references a "Revelation card" that doesn't match
   anything in the data model.** Printed as: "Choose a Revelation card
   in play, and trash it. The emperor immediately draws 2 more and
   chooses 1." Nothing synced from the sheet is called a Revelation —
   is this an old/renamed term for a Location (the Emperor's hand is
   the only thing with a "draw 2, keep 1"-shaped flow elsewhere in
   rules.md, via Prophecies), or a mechanic that hasn't been added to
   the sheet yet? Left unimplemented rather than guessing what a
   Revelation card actually is.

2. **Prohibition of inscribing's grammar is ambiguous about who needs
   to have it equipped.** Printed as: "Any Wanderer with a Relic or
   Golem who adds a Glyph to the Golem mat gains 1 Shame token." Does
   "any Wanderer with a Relic" mean any Wanderer who triggers a
   Glyph-adding Relic (regardless of whether they have Prohibition of
   inscribing itself), or does it only apply to Wanderers who have
   Prohibition of inscribing equipped (which would be an odd way for a
   card to refer to itself)? Left unimplemented pending which reading
   is correct, rather than guessing.

3. **Farmer's insight's "double effect" doesn't map onto a concrete
   mechanic.** Printed as: "Spaces that produce 🌾 have double effect
   for you." Locations don't have a generic recurring "effect" in the
   engine beyond satisfying build costs — is this about search draws,
   some other 🌾-specific interaction, or something not yet in the
   sheet? Left unimplemented pending what "double effect" means here.

4. **Emperor's Timepiece references "all 5 of the Emperor's Relics"
   with no such set defined anywhere.** Printed as: "If you have all 5
   of the Emperor's Relics equipped, swap places with the Emperor,
   then trash this and gain 📜. You are now the Emperor, and they are
   now a Wanderer." Nothing in rules.md or the synced sheet names a
   fixed "Emperor's Relics" set (no `location` field groups 5 Relics
   under an Emperor-flavored heading the way location-locked Relics
   do) — is this a yet-to-be-named Relic subset, or does "Emperor's
   Relics" mean something else entirely (Relics the Emperor has
   personally handed out)? Left unimplemented pending which 5 Relics
   this actually refers to. Separately, note for whenever this is
   resolved: the actual role swap ("you are now the Emperor") crosses
   into the DB layer (GameRole user_id<->role_key assignment), not
   something GameEngine's pure GameState can express on its own — the
   engine would validate the condition and emit an event; the
   controller would do the actual swap.

## Resolved (for my own reference, not standing asks)

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
