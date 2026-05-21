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
 1 Naralis, Year 1 — a blight ruins much of the stores at Sunwell Oasis.
 18 Jarethis, Year 1 — Keshun and Qiqer become partners.
 5 Lunaris, Year 3 — Tosir is born to Lagogik and Kikas.
 28 Naralis, Year 4 — Tosir dies at age 0.
 1 Kalimos, Year 9 — the Sandstorm catches Sunwell Oasis underprepared (readiness 67%); the dust takes its toll.
 12 Mirathis, Year 12 — Takhezu is born to Fajebel and Shamatan.
 12 Mirathis, Year 12 — Fajebel dies in childbirth.
 1 Naralis, Year 14 — a plague sweeps through Sunwell Oasis; the sick fill its homes.
 9 Ra'anis, Year 18 — Keshun dies at age 58.

Population:
 founders 8 · born 5 · died 6 · living now 7
 trajectory ▆▆▇▇▇▇▇██▇▇▇▇▇▇▇▇▆▆▆▅▅ Y1=8 … Y22=7 (peak 11, carrying capacity 19)
 health: avg sickness 39/100 · 3 gravely ill (crowding, famine, frailty, plague)
 mutual aid 66% (generosity shares a famine's shortfall → fewer of the vulnerable lost)

Economy — granary & carrying capacity:
 land yield 22 × tech 1.2 × avg season 0.75 → carrying capacity 19   (technology has ratcheted up from 1.0)
 this year's harvest 105% (ordinary good/lean swing; the granary & mutual aid buffer it)

Cooperation — Sandstorm preparation:
 culture: Tharadi — collectivism 70 · hierarchy 65 · tradition 70 · restraint 60 · piety 65
 faith: the Way of Nara — tenets loyalty & sanctity, binds at 0.66
```

The same seed always produces the same world — which is what makes editing and generation
**legible** rather than chaotic.

```bash
php artisan world:simulate --years=40 --population=24   # a larger, longer run — it outgrows its cohesion and founds (then sheds) a Temple
php artisan world:simulate --seed=lyrion                # a different world
php artisan world:simulate --json                       # also dump chronicle + roster to storage/app/chronicle.json
```

---

## What's happening under the hood

Everything emerges from a small set of interacting systems — no scripted events:

- **Time** — a canonical tick clock projected onto the in-world **Tharadi calendar** (months, seasons, festivals).
- **Agents** — people composed from a **species + region trait registry** (agility, senses, dispositions…) and a stack of **needs** that drive behavior.
- **Population** — emergent pairing, **birth with trait inheritance**, density-dependent fertility, and **U-shaped mortality**: a steep infant/child risk, the peril of childbirth, and the rising frailty of old age, all bounded by a **carrying capacity**.
- **Economy** — resource stockpiles, production & consumption, money, and a carrying capacity computed from **land × technology × season**. Labor produces a **basket of real foodstuffs** (grain, dates, goat meat) that spoil and are **cooked into meals** — a varied diet keeps people well. **Good and lean harvest years**, finite storage, and **shocks** (blight, raids, plague) make scarcity bite.
- **Health** — sickness accrues from crowding, scarcity, frailty, and plague and is eased by a good diet; the sicker an agent, the likelier the end — and the more perilous childbirth.
- **Boom & bust** — it runs on its own now: **technology ratchets up** under sustained population pressure (Boserup), raising the ceiling; overshoot then **exhausts the land** (Diamond/Tainter) and a famine die-back follows; pressure eases, the land recovers fallow, and the cycle turns again.
- **Cooperation** — communal **projects** (preparing for the Sandstorm) powered by four axes: _want-to_ (cohesion × sociability), _faith_ (the devout pitch in), _paid-to_ (hired with money), _forced-to_ (an institution's mandate).
- **Rise & fall** — cohesion decays as a settlement grows; a persistent cooperation deficit makes it **found an institution** to compel what cohesion no longer can; the institution then **ossifies**, costs more than it returns, and **collapses** — and the cycle can begin again.
- **Culture** — a 7-dimension vector (collectivism, hierarchy, tradition…) **generated from material conditions** (a harsh, lean desert breeds restraint and collectivism), which sets the cohesion baseline, shapes dispositions and institution type, and **drifts over time** with material security (prosperity loosens it, scarcity tightens it).
- **Faith** — a **Moral-Foundations** weighting (loyalty, authority, sanctity…) derived from the culture, which binds thrift, generosity, and cooperation — and binds them more for the devout than the nominal believer.
- **Mutual aid** — a generous, collectivist settlement shares a famine's shortfall and loses fewer of its vulnerable; a stingy one hoards (Sahlins' reciprocity).
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

Built so far: the headless engine (**M0**), its foundations (**M1**), the full **pressure → relief → rise & fall** loop (**M2**), the **cultural & social model** with faith (**M8**), a **diet & health** layer (real foodstuffs → meals → well-being), and a pass deepening the simulation's realism — endogenous technology, land degradation, harvest variance, and lifelike mortality — so the single settlement is now a richly interlocked living world. Ahead lie persistence (**M3**), the headline **edit-history-and-ripple** trick (**M4**), backward **generation** from an authored end-state (**M5**), **scale** to many settlements with trade and migration (**M6**), and the visual **presentation** layer (**M7**).

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
