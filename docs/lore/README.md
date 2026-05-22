# Vendored lore — test material, not engine input

This directory holds a snapshot of the **Vaeris** worldbuilding canon, vendored
here as **test material**: an oracle the simulation is checked against, not a
source the engine reads at runtime.

Timeweft is meant to be **lore-agnostic** — in the long run it has *no* hardcoded
world. A world is data (regions, calendars, species, pantheons) the engine
generates, edits, and explains; the worldbuilding-doc import pipeline (TWT-119)
is how an arbitrary world becomes such a bundle. Until that lands the engine
still encodes a little of Tharados in PHP, but the direction is clear: facts
move out of code and into data like this.

## What's here

- `canon/` — the machine-readable Vaeris canon (regions, calendars, species,
  pantheons). Each region records its material conditions (`yield_by_season`,
  derived `scarcity`/`seasonal_volatility`) **and** an `expected_culture` block —
  the culture cultural-materialism predicts from those conditions.
- `realism.md` — the realism loop these files serve: generate culture from
  material conditions, then check the generated culture against the canon's
  `expected_culture`. Divergences are a prompt to revisit either the lore or the
  rules — *magic excepted* (some facts, e.g. Aetherian piety, are exempt because
  magic is the in-world cause, not a missing material pressure).

## How the simulation uses it

`expected_culture` is a **test oracle**. `RealismCheckTest` reads these
conditions, generates cultures through the engine, and asserts the result
respects the canon's qualitative ordering (skipping the documented
`magic_exceptions` and the `hierarchy` carve-out, which the canon notes tracks
surplus/land-tenure rather than scarcity alone).

## Provenance

Snapshot of the Vaeris `canon/` tree (the prose source-of-truth lives in the
separate Vaeris repository). Treat the Vaeris repo as upstream; refresh this
snapshot when the canon changes.
