# 01 · Architecture & Core Model

## Two layers

- **Skeleton (sparse, persisted):** the canonical events — births, foundings, wars,
  falls. Forward-simulated, path-dependent, finite. This *is* the timeline.
- **Texture (dense, derived):** "what is X doing at noon on day 3?" — recomputed on demand
  from the nearest checkpoint, seeded, and then **discarded** — never persisted, even after it is
  looked at or edited; the canonical skeleton stays the only source of truth.

Forward-sim writes the history; derive-on-demand fills the life between the lines.
Storage grows with **attention**, not with time × population.

> Why not pure forward simulation? You can't store every minute of every life across
> arbitrary/custom calendars — infinite state. Why not pure derive-on-demand? Births,
> inheritance, and falling empires are *path-dependent* — you only know who exists in
> year 800 by having run the world there. The two layers resolve the tension.

## Initial-value vs boundary-value generation

- **Seed-forward** ("surprise me"): start from a seed, run forward, discover what emerges.
- **End-state-backward** ("justify my Vaeris"): the author pins the present (and scattered
  waypoints); generation builds a plausible past that *arrives* there (see [08](08-direction-and-generation.md)).

Same machinery, opposite directions.

## The through-line

Projects, institutions, cohesion, resources, authored end-states — they all collapse onto
**a causal graph + steering**. The vision keeps getting wilder while the machinery stays
small; that is the sign it holds together.

## Stack

Laravel 13 (PHP 8.4); PostgreSQL (TimescaleDB available but dormant — see
[10](10-persistence-lod-roadmap.md)). The simulation engine is plain PHP under
`app/Sim/{Time,World,Behavior,Direction,Projects,Chronicle,Support}`. Headless
`php artisan world:simulate` first; the legacy Node timeline app is archived under
`legacy/` and a renderer comes later.
