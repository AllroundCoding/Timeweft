# 13 · World Generation & Geography  ⚪ future

Today the regions — Tharados, the Mirage Coast, the Scorching Mountains — are hand-authored
canon. This doc is the substrate *beneath* the cultural-materialism stack
([11](11-cultural-and-social-model.md)): the geology that generates the geography that generates
climate, minerals, and biomes — and therefore products ([12](12-goods-tastes-tech-conflict.md)),
culture ([11](11-cultural-and-social-model.md)), and conflict
([12](12-goods-tastes-tech-conflict.md)). It is the **root of "generate from materials."**

## The generative stack (root first)

`plates → terrain → climate + minerals → biomes & products → culture → economy → conflict`

Doc 11 generates culture *from* environment; this doc generates the environment itself.

## Tectonics as the root

A seeded plate configuration yields:

- **Terrain** — collisions raise mountains, rifts open valleys and seas, hotspots build volcanic isles.
- **Minerals** — ore and gems concentrate along faults and volcanic zones (this is *why* the
  Scorching Mountains hold gems — canon, [06](06-resources-economy-trade.md) — not hand-placed).
- **Climate** — elevation + latitude + **rain shadow**: a mountain range *explains* the Tharadi
  desert downwind of it.

So the map is *generated from a seed* instead of authored — and the same machinery serves
backward-generation ([09](09-causality-editing-ripple.md)): drop a range to justify an authored desert.

## Fidelity by timescale (the freeze ⇄ simulate dial)

This is **LOD** ([10](10-persistence-lod-roadmap.md)) sliced by timescale, not a binary:

- **Slow geology** (drift, orogeny, erosion) is motionless across centuries → **freeze** it at
  worldgen (LOD-0). It's the stage, and the stage doesn't move during the play.
- **Fast geology** (earthquakes, eruptions) has a human-timescale rhythm — strain builds on a
  fault over decades, then releases → a thin **live** model (LOD-1).

Freeze isn't the opposite of simulation; it's the **substrate simulation runs on** — the frozen
worldgen produces the faults the live model accumulates stress along.

## Hazards as emergent shocks

Each fault carries one number — accumulated stress — ticking up yearly; when it crosses a seeded
threshold it *releases*, magnitude proportional to what had built up. Emergent, cheap,
deterministic, and **legible**: the quake is *earned* (recurring on a fault; a long quiet → "the
big one"). This replaces the flat-random quake — the **`K = 22` of geology**, a placeholder — behind
the same shock interface ([06](06-resources-economy-trade.md)). And because an emergent quake has
a **cause**, it has a place in the causal graph and survives editing/ripple
([09](09-causality-editing-ripple.md)); flat-random noise doesn't.

## Deep time (the door left open)

The freeze is a **dial setting, not a wall**. Widen the timestep from days to millennia and the
frozen layers wake up on their own: continents drift, climates swing through ice ages, and
**species become live agents** — emerge, radiate, go extinct. This is scale-polymorphism in *time*
mirroring the spatial kind ([01](01-architecture.md)): a species has a lifecycle (emerge →
radiate → extinction) exactly as a person or kingdom does (rise → live → fall), and a **mass
extinction is the rise-and-fall at biological scale** — a great filter before the Vulpini age.
No rewrite, just a wider tick. Generate-and-freeze is simply LOD-0 of a system that already knows
how to run live.

## Open questions

- **Output shape** — a heightmap + climate grid, or a coarse region graph? *Lean: start coarse*
  (a handful of regions tagged with terrain / mineral / climate), refine to a grid only when the
  map view ([M7](ROADMAP.md)) needs it.
- **Procedural vs authored** — full procedural plates, or author a few plate seeds and *derive*
  terrain/climate/minerals? *Lean: author the seeds, derive the rest* — controllable yet generative.
- **Where it slots** — worldgen feeds M6 (a real multi-settlement map) and M7 (the 2D map), and
  supplies M9 (products) and M10 (metals + defensible terrain). Likely its own substrate phase
  ahead of M6.

## Status

⚪ Future. The substrate beneath [11](11-cultural-and-social-model.md) and
[12](12-goods-tastes-tech-conflict.md); feeds [06](06-resources-economy-trade.md),
[08](08-direction-and-generation.md), [09](09-causality-editing-ripple.md), and
[10](10-persistence-lod-roadmap.md). Captured on the detail track — build it when the map outgrows
hand-authored regions.
