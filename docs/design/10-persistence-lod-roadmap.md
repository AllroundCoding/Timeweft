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

All seeded & reproducible, headless `php artisan world:simulate`; the canonical `--seed=vaeris` run
stays byte-identical across additive features (a deliberate invariant — see
[`conventions.md`](../conventions.md)).

- **Phase-0 spike:** calendar clock, agents/traits/needs, per-tick behavior, emergence
  (pairing/birth/inheritance/death), story-director milestone, `--json` chronicle artifact.
- **+ collaborative projects v1** (communal Sandstorm prep) and **carrying capacity** (logistic growth).
- **+ institutions** — rise & fall from the cohesion deficit (collapse from ossification *and* insolvency).
- **+ economy (M5):** regional specialization, inter-settlement trade & scarcity pricing, tech-scaled
  storage, cost of living, and the agentic layer — a settlement labor market, professions, and
  agent-driven caravans (mutual aid as an action).
- **+ cross-settlement society (M5):** map coordinates & distance-aware routes, relations/diplomacy,
  raids & open war, distress aid, migration, and per-edge cohesion.
- **+ disease** (contagion along trade/proximity), **worldgen substrate** (plates → elevation/minerals),
  and **LOD (M5):** statistical cohorts with promote/demote and scale-polymorphic group agents.

## Roadmap

1. ~~**Institutions** from the cohesion deficit (upkeep / ossification → rise & fall).~~ ✅
2. ~~**Resources → dynamic K**, then inter-settlement **trade**.~~ ✅
3. **Persistence + schema** (events with provenance) — *next (M6)*.
4. ~~**Retroactive ripple / undo** (Lyrion's Great Flood as the test).~~ ✅
5. ~~**LOD / cohorts** → civilization scale.~~ ✅ *(cohorts + promote/demote shipped; region domain-decomposition pending)*
6. **LLM flavor** layer; **gantt + 2D renderer**; **adaptive ticks + speed dial** — *later (M7)*.
