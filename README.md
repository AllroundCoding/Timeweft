# Timeweft

**A worldbuilder's timeline you can _live inside_.**

Timeweft is a worldbuilder's engine: **generate** a world's history (forward from a seed, or
_backward_ from an authored end-state), **edit** a past event and watch the consequences ripple,
and zoom in to watch life happen. Characters — and households, villages, kingdoms, religions — go
about their lives; history emerges on its own; the author pins the key moments and the world fills a
plausible past and future around them.

The generate–edit–explain core is built today, at the scale of a single settlement. It is a
headless, deterministic engine on **Laravel**; scaling it to a world of many settlements you can
_see_ and keep is what comes next. A timeline / 2-D map renderer is a _view_ onto the engine — the
engine comes first.

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
 1 Naralis, Year 1 — a blight ruins much of the stores at Sunwell Oasis.
 18 Jarethis, Year 1 — Keshun and Qiqer become partners.
 1 Naralis, Year 3 — a lean harvest at Sunwell Oasis; the granary fills slowly (yield 83%).
 5 Lunaris, Year 3 — Tosir is born to Lagogik and Kikas.
 28 Naralis, Year 4 — Tosir dies at age 0.
 1 Kalimos, Year 9 — the Sandstorm catches Sunwell Oasis underprepared (readiness 67%); the dust takes its toll.
 1 Naralis, Year 11 — Sunwell Oasis masters new techniques; its craft and yield advance (technology 1.11).
 1 Naralis, Year 12 — with the deadline pressing, a caravan-master under Varis founds the trading post regardless.
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

Or run generation **backwards** — author an end-state and let the engine justify it, decomposing the
target into the pinned past it requires (and refusing a present that can't be reached in time):

```bash
php artisan world:simulate --end-state=town@40          # "justify a town by Year 40"
# Generation mode: end-state-backward — justifying town@40
#  8 Naralis, Year 1  — the village founds the settlement
#  1 Naralis, Year 15 — … founds the trading post (the town it grows from)
#  1 Naralis, Year 40 — … founds the town
php artisan world:simulate --end-state=empire@50        # refused: an empire by Year 50 can't be reached in time
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
- **Story direction** — an author pins **milestones** in dependency order; the world steers toward them organically, and **soft beats yield to emergence** (they lapse if the world goes another way) while **hard pins must hold** (force-bridged at their deadline, with the conflict surfaced). Top-down beats and bottom-up communal projects run on **one mechanism** — the director can spawn a project the village then works toward.
- **Editing the past** — every event records *why* it happened (its causes), so the chronicle is a causal graph. Edit a past event — undo a disaster — and the engine invalidates only its **downstream cone** and recomputes it deterministically: a clean history-diff, not butterfly chaos. With an append-only edit log and linear + selective **undo/redo**.
- **Generating a world, both directions** — grow **forward** from a seed ("surprise me"), or run the graph **backward** from an authored end-state ("an empire by Year 500") into the pinned past that justifies it — with a **lore checker** that flags pins which can't all be true before they yield a broken world.

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

The north star is a **worldbuilder's tool**: generate, edit, and explore a coherent civilization's
history. That core is built — at the scale of one settlement.

**Built so far:** the headless engine and its foundations (**M0/M1**); the full **pressure → relief →
rise & fall** loop (**M2**); the **cultural & social model** with faith (**M8**); a **diet & health**
layer plus a realism pass (endogenous technology, land degradation, harvest variance, lifelike
mortality); the **edit-history & ripple** machinery — provenance graph, retroactive ripple, edit log,
undo/redo (**M3**); and **two-direction generation** — forward and end-state-backward, with author
pins and a lore checker (**M4**). The single settlement is now a richly interlocked, editable,
generatable living world.

**Next — make the core a world you can use:**

- **Scale (M5)** — one settlement → many, with trade, migration, and regional specialization. The village becomes a *world*.
- **Persistence (M6)** — a database so worlds survive between runs (and can be scrubbed and resumed).
- **Presentation (M7)** — *see* the timeline: a gantt → 2-D map renderer, and an optional LLM flavor layer.

**Act II — someday, deliberately downstream:** "world sim to the max" — a real-time, **playable**
layer where you walk the world as an agent (and maybe with others), an **ecology** of animal and herd
agents, a **celestial** almanac of moons and tides, and itemized goods, tech trees, and conflict
between kingdoms. Sketched in the design docs; it builds *on* the worldbuilding core, not instead of it.

---

## Documentation

The system design lives in **[`docs/design/`](docs/design/)** — start with the
[index](docs/design/README.md) and the [roadmap](docs/design/ROADMAP.md). The documents work out
the architecture, time, agents, behavior, population, economy, cooperation, story direction,
causality & editing, persistence & LOD, the cultural model, and the further-out goods/tech/conflict
and world-generation ideas. A public wiki explaining every mechanic will follow.

---

## Tech & development

- **PHP 8 / Laravel** — the engine is plain PHP under [`app/Sim/`](app/Sim) and runs headless; no database yet (persistence is M6).
- **Deterministic & tested** — a seeded RNG plus a unit + golden-master test suite lock in reproducibility.

```bash
composer install
php artisan world:simulate     # run the world
php artisan test               # run the suite
./vendor/bin/pint              # format
```

Timeweft is a work in progress; the engine and its design evolve together.
