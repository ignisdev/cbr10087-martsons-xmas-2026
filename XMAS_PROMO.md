# The Xmas Drop — Game Spec

> Source brief preserved in the "Brief" section at the bottom. The sections
> above it are the build spec the code implements; if the two disagree, raise
> it — don't silently change the code.

**Copy:** "Win The Xmas Drop." · "Go on, one more drop. It's Xmas After All."

## Summary

A four-level timing game. Pepsi Xmas icons slide across the top of the screen;
the player taps to drop each one straight down into its fixed space on the
Pepsi Xmas Tree. Every piece must stay in place. A bad drop overlaps, tips and
falls off — the tree is incomplete and the run ends. Landing the **star** on
top completes the tree and, inside an active prize time slot, wins.

The icons appear in the **same order every game**; only the player's timing
changes.

## Structure — 15 pieces, 5 layers, 4 levels

| Level | Layers | Pieces | Slider speed | Landing tolerance |
|---|---|---|---|---|
| 1 | rows 0–1 | 9 (5 + 4) | 0.90 /s | ±0.120 |
| 2 | row 2 | 3 | 1.30 /s | ±0.090 |
| 3 | row 3 | 2 | 1.80 /s | ±0.065 |
| 4 | row 4 — **star** | 1 | 2.40 /s | ±0.045 |

Positions are normalised: −1 … +1 across the play width. Anchors are spaced
0.36 apart within a row. Slider travel is ±0.95. Odd pieces enter from the
left, even from the right.

Piece order (bottom row first, left → right):

| Row | Pieces |
|---|---|
| 0 | present, bauble, candy cane, bauble, present |
| 1 | lights, bauble, bauble, lights |
| 2 | candy cane, present, candy cane |
| 3 | bauble, bauble |
| 4 | star |

Values are in `includes/lib/drop-engine.php` (`pf_drop_levels()`,
`pf_drop_pieces()`) and served to the client at `/session/start`. Tune them
there — the TypeScript never hard-codes them. The `pf_drop_levels` filter can
override levels without a code change.

## Pre-set anchors, not physics

Per the brief's technical decision: fixed target positions, not free-form
physics or true Tetris building. Keeps the finished stack identical to the KV,
keeps the code simple, and keeps the server the sole judge of every landing.

## Prizes & the killer piece

- Prize pool: **20 × £250** and **500 × £10**, scheduled per time slot in BPM.
  Slot `prize_type` = `cash_250` or `cash_10`.
- The **star** is the killer piece and the only drop that reserves a slot.
  - **Inside an active slot:** tolerance widens ×2.5 — a near-perfect star seats and wins.
  - **Outside a slot:** the star always wobbles/rolls off, landing exactly where
    it was dropped with the same animations and commentary as an honest miss.
- One play per age-gate entry; daily play cap from `BPM_Play_Limit`.

## 3D assets

Five icon types: `present`, `bauble`, `candy_cane`, `lights`, `star`. The game
ships with placeholder primitives. Final assets: one GLB per type, supplied
via the `pf_piece_model_urls` filter (`[ type => url ]`). Each model is
auto-scaled to the piece box. Re-colour/re-skin for other portfolio brands
(e.g. J2O) by swapping GLBs — same shapes, no code change.

## Still needed from the client / creative

- [ ] Final KV-matched 3D GLBs for the five icon types.
- [ ] Xmas audio: ambience bed + drop "thud" (the `ambience` / `drop` sound
      keys are wired but have no files yet; cheer/groan are reused).
- [ ] BPM prize schedule with `cash_250` / `cash_10` slot types.
- [ ] Confirm the level speeds/tolerances after playtesting on devices.

---

## Brief (original, verbatim)

**THE XMAS DROP**

Insight: Building the Christmas tree creates anticipation, leading to the most satisfying moment: placing the star on top.

What it is: A four-level timing game where players drop Pepsi Xmas icons into position to complete the Pepsi Xmas Tree.

How it works: Players tap to release each moving icon into the available space on the tree. Every icon must remain in place. There is a small tolerance, but if an object lands incorrectly, overlaps another object and falls off, the tree is incomplete and the player loses.

Level 1 More objects, slower movement and more landing space, allowing players to learn—although an overlapping drop can still fail.

Level 2 Fewer objects moving faster, with less space available.

Level 3 Fewer objects again, increased speed and tighter landing areas.

Level 4 The star moves fastest and must land within the smallest space at the top.

The icons appear in the same order every game. Only the player's timing and placement change.

Why it works for Pepsi: The mechanic is instantly understood but increasingly difficult to master, encouraging repeat play. Each successful drop builds the distinctive Pepsi Xmas Tree, with the final star creating a clear and satisfying branded payoff.

1. Game Mechanics & Flow
* The Objective: Players drop Pepsi-themed 3D icons (such as baubles, candy canes, presents, lights, and a final star) to construct a 15-piece Christmas tree structure matching the official Key Visual (KV).
* Layer Progression: The game features five main levels/layers structured like a pyramid (e.g., dropping items sequentially from bottom to top).
* Scaling Difficulty: As the player progresses toward the top of the tree, the speed of the sliding items increases while space decreases, requiring tighter timing and accuracy.

2. Prize Distribution & The "Killer Piece" Mechanic
* Time Slot Control: Because there is a limited prize pool (20 x £250 and 500 x £10), winning must be strictly linked to active winning time slots rather than pure player skill.
* The "Killer Piece" / "Devil's Bauble": To prevent players from winning outside of active time slots, the back-end introduces a planned poison piece (such as an unstable snow globe or an assassin snowman).
* Mechanic Logic:
    * Outside a Time Slot: The killer piece will intentionally land at an awkward angle or roll off the stack - creating a believable "near-miss" failure.
    * Inside an Active Time Slot: The system widens the tolerance, allowing the piece to land upright or anchor in smoothly, enabling the player to complete the tree and win.

3. Technical & Design Decisions
* Pre-Set Anchor Positions vs. Physics: The team agreed to use fixed target drop positions rather than free-form physics or Tetris-style building. This keeps the programming simple, ensures the finished stack matches the brand's exact Christmas tree design, and controls execution time.
* 3D Asset Reusability: The 15 core 3D baubles will be created once and then re-coloured/re-skinned, keeping the shape identical, for any broader portfolio versions (such as changing a red/blue Pepsi candy cane to orange for J2O) to avoid doubling 3D modeling costs and development hours.
