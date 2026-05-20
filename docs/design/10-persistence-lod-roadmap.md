# 10 · Persistence, LOD & Roadmap

## Persistence  🟡

Plain **PostgreSQL**:

- JSONB for deep/arbitrary traits ([03](03-agents-traits-needs.md));
- an `events` table + an `event_dependencies` edge table + recursive CTEs for causal cones
  ([09](09-causality-editing-ripple.md));
- relational tables for entities and relationships.

**TimescaleDB** stays a *dormant* extension — enable hypertables per-table only if we ever
bulk-persist dense texture, and **never** on the canonical events (ripple mutates those, which
fights time-series compression). The append-only **edit log** is the one genuinely
time-series-shaped table. Settle the schema only now that the derivation model is proven.

## Level of detail (LOD)

- **Tracked agents** — full individuals, simulated in detail.
- **Statistical cohorts** — a city is birth/death rates + distributions, not 50,000 agents.
- **Promote** a cohort member to a tracked agent the moment they matter; **demote** when they
  fade. The same "concretize only when observed" trick as derive-on-demand, applied to
  *existence* instead of *activity*.

Detail follows attention — in space (LOD) and in time (adaptive ticks,
[02](02-time-and-calendar.md)).

## Scale-polymorphism

Person / tribe / kingdom / religion are the same engine at different scales; institutions are
persistent **group-scale agents** born from cooperation
([07](07-cooperation-projects-institutions.md)).

## Build status  🟢

Branch `rewrite/laravel` (all seeded & reproducible, headless `php artisan world:simulate`):

- **Phase-0 spike:** calendar clock, agents/traits/needs, per-tick behavior, emergence
  (pairing/birth/inheritance/death), story-director milestone, `--json` chronicle artifact.
- **+ collaborative projects v1** (communal Sandstorm prep).
- **+ carrying capacity** (logistic growth, population trajectory sparkline).

## Roadmap

1. **Institutions** from the cohesion deficit (+ upkeep / ossification curve → rise & fall).
2. **Resources → dynamic K**, then inter-settlement **trade**.
3. **Persistence + schema** (events with provenance).
4. **Retroactive ripple / undo** (Lyrion's Great Flood as the test).
5. **LOD / cohorts** → civilization scale (tribes → kingdoms).
6. **LLM flavor** layer; **gantt + 2D renderer**; **adaptive ticks + speed dial**.
