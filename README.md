# Timeweft

**A worldbuilder's timeline you can _live inside_.**

Timeweft is a gamified life- and world-simulator: seed or author a world, simulate it forward,
scrub to any point in time, zoom in to watch life happen, and (eventually) edit history and watch
the consequences ripple. Characters — and households, villages, kingdoms, religions — go about
their lives; history emerges on its own; the author pins the key moments and the world fills in a
plausible past and future around them.

It is being rebuilt on **Laravel** as a headless, deterministic simulation engine. A timeline /
2-D map renderer is a _view_ onto that engine, added later — the engine comes first.

---

## See it run

The whole world today lives behind one command:

```bash
php artisan world:simulate
```

A single seeded run of the oasis village **Sunwell Oasis** (region Tharados) tells its own story —
nothing below is scripted; it all falls out of the rules:

```
Chronicle:
 1 Naralis, Year 1 — the village first observes the Renewal of Nara.
 1 Naralis, Year 1 — raiders strike Sunwell Oasis; 2 souls are lost.
 6 Naralis, Year 1 — Lagogik and Shamatan become partners.
 3 Kalimos, Year 1 — Gati is born to Keshun and Movu.
 1 Kalimos, Year 8 — the Sandstorm catches Sunwell Oasis underprepared (readiness 70%); the dust takes its toll.
 1 Kalimos, Year 10 — after 3 storms caught it short, Sunwell Oasis founds the Temple of Nara to compel the preparation cohesion alone could not muster.
 1 Naralis, Year 17 — the Temple of Nara, ossified and extracting more than it returns, collapses; Sunwell Oasis falls back on its own cohesion.

Population:
 founders 8 · born 3 · died 4 · living now 7
 trajectory █▆▇███████████████████ Y1=7 … Y22=7 (peak 7, carrying capacity 16)

Cooperation — Sandstorm preparation:
 culture: Tharadi — collectivism 69 · hierarchy 64 · tradition 69 · restraint 59 · piety 64
 generated from materials (structural scarcity 0.75 · volatility 0.50), then drifts with material security
```

The same seed always produces the same world — which is what makes editing and generation
**legible** rather than chaotic.

```bash
php artisan world:simulate --years=40 --population=16   # a longer, larger run
php artisan world:simulate --seed=lyrion                # a different world
php artisan world:simulate --json                       # also dump chronicle + roster to storage/app/chronicle.json
```

---

## What's happening under the hood

Everything emerges from a small set of interacting systems — no scripted events:

- **Time** — a canonical tick clock projected onto the in-world **Tharadi calendar** (months, seasons, festivals).
- **Agents** — people composed from a **species + region trait registry** (agility, senses, dispositions…) and a stack of **needs** that drive behavior.
- **Population** — emergent pairing, **birth with trait inheritance**, and death, bounded by a **carrying capacity**.
- **Economy** — resource stockpiles, production & consumption, money, and a carrying capacity computed from **land × technology × season**. Seasonal yield, finite storage, and **shocks** (blight, raids) make scarcity bite.
- **Boom & bust** — overshoot the land or empty the granary and a famine die-back follows; recovery begins again.
- **Cooperation** — communal **projects** (preparing for the Sandstorm) powered by three axes: _want-to_ (cohesion × sociability), _paid-to_ (hired with money), _forced-to_ (an institution's mandate).
- **Rise & fall** — cohesion decays as a settlement grows; a persistent cooperation deficit makes it **found an institution** to compel what cohesion no longer can; the institution then **ossifies**, costs more than it returns, and **collapses** — and the cycle can begin again.
- **Culture** — a 7-dimension vector (collectivism, hierarchy, tradition…) **generated from material conditions** (a harsh, lean desert breeds restraint and collectivism), which sets the cohesion baseline, shapes dispositions and institution type, and **drifts over time** with material security (prosperity loosens it, scarcity tightens it).
- **Personality** — a per-agent **Big Five (OCEAN)** layer beneath culture, so two members of one culture still differ.
- **Story direction** — an author can pin **milestones**; the world steers toward them organically, or forces them as a deadline arrives.

### The small set of primitives

The scope is enormous but the machinery stays small. Every system reduces to a handful of reused mechanisms:

1. **Scale-polymorphic agents** — a person, household, village, or kingdom are all entities with traits, needs, and a lifecycle (rise → live → fall), run by one engine at different levels of detail.
2. **A causal dependency graph** — events carry their preconditions. Run it _forward_ for emergence; run it _backward_ to generate a past that justifies an authored present.
3. **Steering toward goals on a time budget** — top-down (author milestones) and bottom-up (group projects) are the _same_ mechanism.
4. **Derive-on-demand** — a sparse canonical skeleton is persisted; dense texture is computed when observed, then crystallized into canon.
5. **Seeded determinism** — same seed → same world.
6. **Cohesion & carrying capacity** — the social and material limits that bound and shape everything else.

---

## Where it's going

Built so far: the headless engine (**M0**), its foundations (**M1**), the full **pressure → relief → rise & fall** loop (**M2**), and most of the **cultural & social model** (**M8**). Ahead lie persistence (**M3**), the headline **edit-history-and-ripple** trick (**M4**), backward **generation** from an authored end-state (**M5**), **scale** to many settlements with trade and migration (**M6**), and the visual **presentation** layer (**M7**).

The vision reaches further still — itemized goods & cuisine, technology trees and conflict between kingdoms, and a geology-up world generator — sketched in the design docs.

---

## Documentation

The system design lives in **[`docs/design/`](docs/design/)** — start with the
[index](docs/design/README.md) and the [roadmap](docs/design/ROADMAP.md). The documents work out
the architecture, time, agents, behavior, population, economy, cooperation, story direction,
causality & editing, persistence & LOD, the cultural model, and the further-out goods/tech/conflict
and world-generation ideas. A public wiki explaining every mechanic will follow.

---

## Tech & development

- **PHP 8 / Laravel** — the engine is plain PHP under [`app/Sim/`](app/Sim) and runs headless; no database yet (persistence is M3).
- **Deterministic & tested** — a seeded RNG plus a unit + golden-master test suite lock in reproducibility.

```bash
composer install
php artisan world:simulate     # run the world
php artisan test               # run the suite
./vendor/bin/pint              # format
```

Timeweft is a work in progress; the engine and its design evolve together.
