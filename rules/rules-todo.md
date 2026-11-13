# Rules TODOs

Ambiguities, gaps, and things to formalize in `rules.md`. I don't edit
`rules.md` — this is the list for you to work from. I remove items once
they're resolved and fold the resolution into my own understanding instead
(no need to keep a changelog here).

## Open

1. **Golem card tapping needs real engine support.** Confirmed via chat
   (2026-11-13): "tapped" means flipped face-down; when resolution reaches
   a tapped card it does nothing and gets discarded. What's still an
   engine task (not a rules question anymore): several Relics tap cards
   in the Golem's draw pile directly (Blinding explosion, Whip of
   Justice, Defanging tool, Golem log, Silent hunting cloak, Peacemaker's
   spirit, Soothing Lullaby) — the draw pile currently has no per-card
   tapped state to flip.

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
