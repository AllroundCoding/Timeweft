# Roadmap

The design docs (01–13) describe *what* the simulator is. This file is the **broad-strokes
arc** of how it gets built — the phases and their throughlines, not the task list.

The granular, task-level backlog lives in **Linear** (project *Timeweft*), one issue per piece
of work, grouped by these same milestones and labelled by design-doc area. Treat Linear as the
source of truth for *what's next*; treat this file as the map of *where it's all going*.

## Built so far (M0)

The headless, seeded engine under `app/Sim/`, run by `php artisan world:simulate`: a canonical
tick clock projected onto the Tharadi calendar; agents composed from species + region traits and
needs; per-tick behavior; emergent pairing, birth-with-inheritance, and death under a carrying
capacity; collaborative projects (Sandstorm prep); cohesion that decays with settlement size;
institutions that emerge from a persistent cooperation deficit; and a story director steering
toward authored milestones. All seeded and reproducible.

## The arc ahead

Each phase builds on the last; the order is dependency-aware so prerequisites land first.

- **M1 · Foundations** — the substrate the rest leans on: a typed trait registry, a culture
  vector, and engine test coverage to lock in determinism before more systems pile on.
- **M2 · Pressure → relief loop** *(the next big payoff)* — resources and a carrying capacity
  computed from them, so growth strains the settlement; institutions that relieve the strain;
  then upkeep and ossification that drag it back down. Rise and fall, emergent.
- **M3 · Persistence** — move from pure in-memory to a persisted skeleton (Postgres), with
  events carrying their causal provenance. Sets up editing.
- **M4 · Editing** — the headline trick: edit a past event and watch the consequences ripple
  forward through the causal graph, with undo.
- **M5 · Generation** — run the graph backward: from an authored end-state, generate a plausible
  past that justifies it.
- **M6 · Scale** — many settlements, trade, and migration; level-of-detail so the world can grow
  from a village to a civilization without simulating every soul.
- **M7 · Presentation** — the visual phase: a narrative flavor layer and a timeline → 2D map
  renderer over the deterministic engine.
- **M8 · Culture & social model** — the deep cultural/social layer ([11](11-cultural-and-social-model.md))
  off the minimal culture vector: apply culture broadly, generate it from material conditions, drift
  it with prosperity, plus the personal (Big Five) and faith (Moral Foundations → tenets) layers. A
  thematic bucket whose pieces interleave with the economy rather than strictly following M7.
- **M9 · Goods, tastes & trade** — produce and equipment become *items with stats*; regions
  generate their own goods from material conditions; tastes (seeded from scarcity) turn trade
  from moving-surplus into spice routes and luxury demand. Deepens M6's trade. See
  [12](12-goods-tastes-tech-conflict.md).
- **M10 · Technology & conflict** — the `technology` scalar becomes a tree of advances (the Boserup
  ratchet); military items give a kingdom a *strength factor*; wars resolve by strength × a
  counter-matrix, opening the **external** door to rise-and-fall alongside internal institutional
  collapse. See [12](12-goods-tastes-tech-conflict.md).

M9–M10 build on **M6**'s scale-polymorphic kingdoms; **M7** (presentation) and **M8** (culture) are
overlays that can interleave whenever there's enough to support them. The numbering is dependency
order, not strict sequence.

Beneath all of this sits a **world-generation substrate** ([13](13-world-generation-geography.md)):
geology that generates terrain, climate, and minerals — the root of "generate from materials" —
landing around M6/M7 when the map outgrows hand-authored regions. Slow geology is frozen at
worldgen; fast hazards (quakes, eruptions) run as emergent shocks, and the same engine could one
day widen its timestep into deep-time, prehistoric ages.

See the design docs for the systems behind each phase, and Linear for the work itself.
