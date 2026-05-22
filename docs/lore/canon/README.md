---
title: Vaeris Canon — Machine-Readable Data
summary: How the canon/ tree is structured and how the Timeweft engine consumes it.
---

# Vaeris Canon (data)

The machine-readable extract of the Vaeris lore in [`../docs/`](../docs/). Where `docs/` carries
prose nuance, `canon/` carries the numbers and structures a program can load — calendars,
regional material profiles, species trait registries, and pantheons.

**The prose is the source of truth.** These files are derived from it. Each file names the
`docs/` page (and original Google Doc) it was extracted from.

## Who reads this, and why

Primarily [Timeweft](https://github.com/allroundcoding/timeweft), a deterministic civilization
simulator. The relationship runs both ways:

- **Generation input** — Timeweft *generates* Vaeris from these files: the calendar projects its
  clock, a region's material profile seeds its economy and (via cultural materialism) its
  culture, the species registry generates its agents, the pantheon shapes its faith.
- **Realism check** — because the engine generates culture *from* material conditions, each
  region file records the culture those conditions *should* produce (`expected_culture`), with
  any `tensions` where the authored lore and the prediction diverge. Those tensions are prompts
  to revisit one or the other. Facts that are genuinely *caused by magic* are exempt instead, and
  marked with a `magic_exceptions` entry — see [`../docs/realism.md`](../docs/realism.md) for the
  realism loop and the magic carve-out policy.

Today Timeweft hard-codes copies of some of these facts in PHP (e.g.
`App\Sim\Time\TharadiCalendar`). Each file's `timeweft:` block notes how the engine consumes it,
or that it is `not_yet_consumed` — informational only, never canonical. The data is the target
the code should track, not the other way round.

## Tree

```
canon/
  calendar/   calendar systems                  — tharadi.yaml 🟢, aetherian.yaml 🟢, elenwood.yaml 🟢, draknar.yaml 🟢, nyralthia.yaml 🟢
  regions/    per-region material profiles       — tharados 🟢, aetheria 🟢, elenwood 🟢, draknar 🟢, nyralthia 🟢; frostlands 🟠, zuldar-wastes 🟠, luthara 🟠, shadowed-jungles 🟠
  species/    species trait registries           — vulpini.yaml 🟢
  pantheon/   gods + moral-foundation leanings    — tharadi.yaml 🟢, aetherian.yaml 🟢, elenwood.yaml 🟢, draknar.yaml 🟢, nyralthia.yaml 🟢
```

🟢 migrated (full) · 🟠 outline (environmental profile only) · ⚪ planned

The five major regions have full calendar, region, and pantheon canon. The four **outline**
regions (Frostlands, Zuldar Wastes, Luthara, Shadowed Jungles) from the smaller-kingdoms document
have an environmental region profile only — no calendar or pantheon, since the source defines
neither. Luthara and the Shadowed Jungles defer `expected_culture` (their societies are barely
documented). The species registry currently holds the single playable species, the Vulpini.

## Conventions

- **Format** — YAML, one concept per file, lowercase-with-hyphens filenames.
- **Provenance** — each file carries `id`, `name`, `summary`, and a `source:` pointer
  (`gdoc:<id>` or a `docs/` path).
- **Scales** — dispositional / cultural values use a 0–100 scale unless noted.
- **Derived values** — numbers computed from other canon (e.g. scarcity from seasonal yields)
  are marked `derived` and are informational; the inputs are the canon.
- **Realism** — `expected_culture` carries `tensions` (divergences to resolve) and, where magic
  is the in-world cause, `magic_exceptions` (exempt facts). See [`../docs/realism.md`](../docs/realism.md).
- **`timeweft:` block** *(optional)* — how the engine consumes the file (or `not_yet_consumed`)
  and any simplifications. Non-canonical; for orientation only.
