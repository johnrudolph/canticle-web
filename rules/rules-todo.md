# Rules TODOs

Ambiguities, gaps, and things to formalize in `rules.md`. I don't edit
`rules.md` — this is the list for you to work from. I remove items once
they're resolved and fold the resolution into my own understanding instead
(no need to keep a changelog here).

## Open

1. **Road resource-network rule is missing from `rules.md` entirely.**
   Confirmed via chat (2026-11-12): a space with a Road "produces" every
   resource produced anywhere along its connected Road network, not just
   its own printed resource. This needs to be written into the Infrastructure
   section — right now `rules.md` only says Roads let Wanderers move through
   for free, nothing about resource-sharing for Location build requirements.

2. **No win/loss thresholds anywhere in `rules.md`'s Game End section.**
   Confirmed via chat: 12 Prophecy = simple victory, 16 Prophecy = elite
   victory. When a Golem-pilot is in play, Wanderers/Emperor lose if they
   fail to reach 12 Prophecy by the end of round 8. None of this is written
   down — "count your prophesy" is all the current text says.

3. **Golem tie-breaking on 🦶 pathing isn't explicit.** Confirmed via chat:
   when two Wanderers are equidistant for "shortest path to nearest
   Wanderer," it's the Emperor's call — same as the 👊 multi-target rule.
   The existing "if still ambiguous, the Emperor chooses" catch-all is right
   next to the 👊 bullet, not the 🦶 one, so it reads like it might only
   apply to attacks. Worth an explicit callout on the movement rule too.

4. **How do Infrastructure tokens enter the Emperor's pool?** Still open —
   wasn't covered in the chat answers. The Emperor Mat says "Play 2
   Infrastructure tokens if you have any in your pool," implying some other
   step adds tokens to it (Location "fixture outlines" is my best guess),
   but nothing confirms the mechanism or quantities, and `locations.json`
   only has a binary `has_shard` flag, not counts or which of the 4
   Infrastructure types a given Location grants.

5. **"Canals" and "Bridges" vs. the 4 real Infrastructure types.** Still
   open. `rules.md`'s Components section lists "Monolith Shards, Canals,
   and Bridges" as fixture examples, but the only real Infrastructure types
   anywhere (Emperor Mat, card data) are Monolith Shard, Road, Walls, Fruit
   tree. Is "Canals and Bridges" just stale wording that should say "Roads,"
   or are those a real, undocumented fixture type?

6. **What makes a Golem card "tapped"?** Still open. The Golem Mat explains
   what happens *when* a card is tapped (skip resolution, discard
   face-up), but nothing explains what causes a card to become tapped in
   the first place.

7. **Draw-back-up discrepancy.** Still open. `rules.md` step 5 of the
   Emperor phase says "draw back up to 5," the Emperor Mat image says
   "Draw back up to 6." One of these needs to change.

8. **Shame/Scar token placement — whose choice?** Still open. Worded on the
   Wanderer's own mat ("placed in a Relic slot of your choice"), so my
   working assumption is the affected Wanderer picks which of their own
   slots gets blocked, not the Emperor/Golem-pilot imposing it — but this
   wasn't explicitly confirmed, flagging in case that's wrong.

9. **Bound Relic exception has no resolution.** Still open. A found Bound
   Relic "must equip after discovering, unless this slot is Scarred, or
   already has a Bound Relic" — if an exception applies, what happens to
   the Relic instead (trashed? held unequipped)?

## Resolved (for my own reference, not standing asks)

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
  multiple cost symbols at once (moot for most Locations today since none
  currently produce multiple resources, but relevant once Road networks are
  involved).
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
  one explicit exception — dropping/picking up a Relic on your own space is
  always available to any Wanderer, any time, turn or not (this is how
  Relics get handed off between Wanderers for free).
- Each player gets their own focused/private UI view, but needs an activity
  log / step-by-step replay of what other players and the Golem did on
  their turns (mirrors beast-master-web's event log + spotlight pattern).
