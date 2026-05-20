# Roadmap & Backlog

The design docs (01–11) describe *what* the simulator is. This file breaks them into
*small, concrete tasks* so each work session can pick **one** and finish it — instead of
sprawling. Keep it in sync as things land.

**Size:** `[S]` ≈ an afternoon · `[M]` ≈ a focused session · `[L]` ≈ multi-session.
**Status:** `✅` built · `⬜` todo. Each task notes its design doc.

## Status snapshot (built so far, `app/Sim/`)

Tharadi calendar clock · composable species+region traits & needs · per-tick behavior
(routine/season/festival) · emergent pairing/birth-with-inheritance/death · carrying-capacity
logistic growth · collaborative projects v1 (Sandstorm prep) · size-decaying cohesion ·
story-director milestone · `world:simulate` with `--json` chronicle. All seeded & reproducible.

## Suggested build order (dependency-aware)

Per-file lists are below, but build in roughly this order so prerequisites land first:

- **M1 — Foundations:** trait registry (03) · cohesion as a computed value (07) · minimal culture vector (11).
- **M2 — Pressure → relief loop (the next big payoff):** resource stockpiles + dynamic K (06) → institutions from a persistent cooperation deficit (07) → upkeep + ossification → rise & fall.
- **M3 — Persistence:** Postgres schema + migrations; events carry causal provenance (10, sets up 09).
- **M4 — Editing:** retroactive ripple + undo (09) — needs provenance from M3.
- **M5 — Generation:** waypoint constraint graph + backward decomposition (08) — needs the causal graph.
- **M6 — Scale:** LOD tracked-vs-cohort (10) · inter-settlement trade (06).
- **M7 — Presentation:** LLM flavor layer · gantt timeline → 2D map renderer.

`#1 next` = the start of **M2: institutions from the cohesion deficit.**

---

## 01 · Architecture
- ⬜ Formalize the skeleton/texture boundary in code (Chronicle vs derived state) `[M]`
- ⬜ Introduce a checkpoint abstraction (boundary state + seed) for future derive-on-demand `[M]`

## 02 · Time & Calendar
- ✅ Canonical tick clock + Tharadi calendar projection
- ⬜ Calendar *interface* + registry (so cultures register their own) `[S]`
- ⬜ Aetherian 10-month calendar + cross-calendar conversion/anchoring `[M]`
- ⬜ Adaptive tick granularity (resolution follows narrative density) `[L]`
- ⬜ Playback speed control (realtime → months/sec) `[M]`

## 03 · Agents, Traits & Needs
- ✅ Composable species+region traits, needs, inheritance
- ⬜ Trait *registry* — typed definitions + values instead of ad-hoc keys `[M]`
- ⬜ Economic/social disposition traits (generosity, thrift, spender/saver) `[S]`
- ⬜ Special entities: omit need components (a god / Istari-type) `[S]`
- ⬜ Faith-tenet modifiers on dispositions `[M]` *(needs 11)*

## 04 · Behavior & Resolution
- ✅ Routine, hunger override, season shelter, festival
- ⬜ Make the priority stack data-driven/configurable `[S]`
- ⬜ Project-commitment behavior (agents visibly "Contributing") `[S]`
- ⬜ Derive-on-demand reconstruction from a checkpoint `[L]` *(needs 01)*

## 05 · Population & Emergence
- ✅ Pairing/birth/inheritance/death · carrying-capacity logistic growth
- ⬜ Disease/health as a need + mortality factor `[M]`
- ⬜ Migration between settlements `[M]` *(needs 06/10)*
- ⬜ Make the boom-bust loop explicit once resources land `[M]` *(needs 06)*

## 06 · Resources, Economy & Trade
- ⬜ Resource stockpiles on agents + settlements (food, water, money) `[M]`
- ⬜ Production/consumption model; **compute K from it** (replace the fixed 22) `[L]`
- ⬜ Environmental yield modifiers (season, region) `[M]`
- ⬜ Money + spender/saver behavior on transactions `[M]` *(needs 03 dispositions)*
- ⬜ Supply/demand pricing within a settlement `[L]`
- ⬜ Inter-settlement trade (raises *effective* K) `[L]` *(needs 10 multi-settlement)*
- ⬜ Regional specialization (comparative advantage) `[M]`
- ⬜ Shocks: famine/war as resource/population events `[M]`

## 07 · Cooperation, Projects & Institutions
- ✅ Projects v1 (Sandstorm prep) · cohesion × sociability participation
- 🟡 Cohesion as a *computed* value — ✅ decays with settlement size; ⬜ per-edge between settlements `[M]` *(per-edge needs multi-settlement, 10)*
- ⬜ Generalize Project (initiator, recruitment, types beyond storm-prep) `[M]`
- ⬜ Three-axis participation: want-to / paid-to / forced-to `[M]`
- ⬜ **Institution entity + emergence from a persistent cooperation deficit** `[L]` ← M2
- ⬜ Institution upkeep cost + ossification/corruption → collapse (rise & fall) `[L]` ← M2
- ⬜ Director-spawns-projects unification `[M]` *(needs 08)*

## 08 · Story Direction & Generation
- ✅ Milestone steering (organic / forced-by-deadline)
- ⬜ Multiple milestones + dependency ordering `[M]`
- ⬜ Author's hand: soft-default conflict surfacing + hard pins `[M]` *(needs 09)*
- ⬜ Waypoint constraint graph + backward decomposition (end-state generation) `[L]`
- ⬜ Lore consistency checker (flag unsatisfiable waypoints) `[M]`
- ⬜ Two modes: seed-forward vs end-state-backward `[L]`

## 09 · Causality, Editing & Ripple
- ⬜ Events carry causal preconditions/provenance (emission + schema) `[L]` ← foundational
- ⬜ Causal dependency graph + downstream-cone query `[L]`
- ⬜ Retroactive ripple: invalidate cone + recompute `[L]`
- ⬜ Event-sourcing tombstones + edit log (the two histories) `[M]`
- ⬜ Undo/redo (linear) → selective undo (rebase) `[L]`
- ⬜ Lyrion's-Great-Flood test scenario `[S]`

## 10 · Persistence, LOD & Scale
- ⬜ Postgres schema + migrations (entities, JSONB traits, events, event_dependencies) `[L]`
- ⬜ Wire the Docker TimescaleDB container as the dev DB (dormant, no hypertables) `[S]`
- ⬜ Persist/load world state (replace pure in-memory) `[L]`
- ⬜ LOD: tracked agents vs statistical cohorts `[L]`
- ⬜ Promotion/demotion between cohort and tracked agent `[L]`
- ⬜ Scale-polymorphic group agents (settlement/kingdom as an agent) `[L]`

## 11 · Cultural & Social Model
- ⬜ Culture vector (7 dimensions) on a Culture entity `[M]`
- ⬜ Apply culture → cohesion / participation / disposition / institution-type `[M]`
- ⬜ Generate culture from material conditions (Cultural Materialism) `[L]`
- ⬜ Culture drift with material security (Inglehart) `[M]`
- ⬜ Big Five personality layer on agents `[S]`
- ⬜ Moral Foundations → faith tenets `[M]`

## Cross-cutting
- ⬜ LLM flavor layer (narrative gen, cached/anchored to tick) `[L]`
- ⬜ Renderer: gantt timeline view → 2D map (the visual phase) `[L]`
- ⬜ Test suite for the engine (Pest/PHPUnit) `[M]`
