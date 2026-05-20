# Timeweft — Civilization Simulator: Design

Timeweft is being rebuilt (Laravel) from a worldbuilding *timeline app* into a
**gamified life / world simulator**: seed or author a world, simulate it forward,
scrub to any point in time, zoom in to watch life happen, and edit history to see
the consequences ripple. These documents work out the systems that make it up.

## The core idea

A worldbuilder's timeline you can *live inside*. Characters — and tribes, kingdoms,
religions — go about their lives; history emerges on its own; the author can pin key
points and the world fills in a plausible past and future around them.

## The small set of primitives

The scope is enormous but the machinery stays small. Every system here reduces to a
handful of reused mechanisms:

1. **Scale-polymorphic agents** — a person, household, village, kingdom, or religion
   are all entities with traits, needs, relationships, and a lifecycle (rise → live →
   fall), run by one engine at different **levels of detail (LOD)**.
2. **A causal dependency graph** — events carry their preconditions. Run it *forward* =
   emergence and retroactive ripple; run it *backward* = generate a past that justifies
   an authored present.
3. **Steering toward goals on a time budget** — top-down (author milestones) and
   bottom-up (group projects) are the *same* mechanism.
4. **Derive-on-demand + materialize-on-observation** — a sparse canonical *skeleton* is
   persisted; dense *texture* is computed when looked at, then crystallized into canon.
5. **Seeded determinism** — same seed → same world, which makes both editing and
   generation *legible* rather than chaotic.
6. **Cohesion & carrying capacity** — the social and material limits that bound and
   shape everything else.

## Documents

| # | System | Status |
|---|--------|--------|
| [01](01-architecture.md) | Architecture & core model | mixed |
| [02](02-time-and-calendar.md) | Time & calendar | 🟢 built |
| [03](03-agents-traits-needs.md) | Agents, traits & needs | 🟢/🟡 |
| [04](04-behavior-and-resolution.md) | Behavior & time resolution | 🟢/🟡 |
| [05](05-population-and-emergence.md) | Population & emergence | 🟢/🟡 |
| [06](06-resources-economy-trade.md) | Resources, economy & trade | 🟡 |
| [07](07-cooperation-projects-institutions.md) | Cooperation, projects & institutions | 🟢/🟡 |
| [08](08-direction-and-generation.md) | Story direction & generation | 🟢/🟡 |
| [09](09-causality-editing-ripple.md) | Causality, editing & ripple | 🟡 |
| [10](10-persistence-lod-roadmap.md) | Persistence, LOD & roadmap | 🟡/⚪ |
| [11](11-cultural-and-social-model.md) | Cultural & social model | 🟡 |
| [12](12-goods-tastes-tech-conflict.md) | Goods, tastes, technology & conflict | ⚪ |
| [13](13-world-generation-geography.md) | World generation & geography | ⚪ |

See **[ROADMAP.md](ROADMAP.md)** for the broad-strokes build arc (the phases M0–M9). The
granular task backlog lives in **Linear** (project *Timeweft*) — one issue per piece of work,
grouped by those milestones; pick one per session to keep scope tight.

## Status legend

🟢 Built · 🟡 Designed · ⚪ Future

The engine lives under `app/Sim/` and runs headless via `php artisan world:simulate`.
A gantt/2D renderer is a *view* onto its output, added later.
