# 05 · Population & Emergence  🟢 built (core) · 🟡 (boom-bust)

## Emergent demographics  🟢

Evaluated once per in-world day:

- **Pairing** — unpaired adults form bonds (kinship-guarded: no parent/child or full siblings).
- **Birth** — a fertile bonded female bears a child, spaced ~2 years; the child's traits are
  `f(parentA, parentB) + seeded mutation`.
- **Death** — Gompertz-ish mortality rising with age; a dead partner unbonds the survivor.

Each is recorded as a canonical chronicle event. A 22-year run already produces a believable
multi-generational saga (founding couples → births → second-generation pairings → grandchildren).

## Carrying capacity → logistic growth  🟢

Population is **bounded** so a small oasis village stays small:

- births scale by `(1 − pop / K)` — fertility tails off as the population nears capacity `K`;
- mortality gains a **famine** multiplier when `pop > K` — overcrowding pulls it back.

Result: logistic growth that plateaus near `K` instead of exploding (verified: a 60-year run
settles at ~21 against `K = 22`). `K` is grounded as an oasis ceiling (canon) and is a
**swappable interface** — later the *output* of the resource system ([06](06-resources-economy-trade.md)).

## Boom-bust (Malthusian) cycle  🟡

Surplus → population boom → demand exceeds production → famine/die-off → surplus restored →
repeat. Already half-emergent from logistic growth + famine. "Improvements made" =
innovation/institutions **raising K** (the Boserupian counter-ratchet) — ties to
[06](06-resources-economy-trade.md) and [07](07-cooperation-projects-institutions.md).

**Code:** `app/Sim/World/EmergenceEngine`, `app/Sim/World/World` (per-day tick).
