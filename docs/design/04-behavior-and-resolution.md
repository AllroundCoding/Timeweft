# 04 · Behavior & Time Resolution  🟢 built (per-tick behavior) · 🟡 (derive-on-demand)

## Behavior derivation

Each tick, an agent's activity comes from a **priority stack**:

`critical need  >  project commitment  >  festival  >  need-driven deviation  >  daily routine`

Built: the daily routine (sleep / breakfast / work / lunch / work / supper / socialize /
sleep), a hunger override (eat off-schedule when starving), Sandstorm midday **sheltering**,
and festival **celebrating**.

## Calendar-driven behavior  🟢

The calendar itself drives behavior — god-days and months trigger observances. Built: the
new-year **Renewal of Nara**. Canon hooks for later: caravans depart in Jarethis, contracts
sign in Varith, astrology peaks in Mirathis.

## Derive-on-demand  🟡

Moment-to-moment texture is not stored per tick; it is computed from committed state + the
seed when observed, then **materialized** (written back as canon — see
[01](01-architecture.md) and [09](09-causality-editing-ripple.md)). Reproducible because
seeded: the same moment always renders the same way until something upstream changes.

**Code:** `app/Sim/Behavior/{Activity, BehaviorEngine, FestivalCalendar}`.
