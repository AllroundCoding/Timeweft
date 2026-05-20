# 02 · Time & Calendar  🟢 built (clock + calendar)

## Canonical clock

One integer **tick** is the world's true time (currently `1 tick = 1 hour`). Everything
else is derived from it. The simulation advances the tick; nothing stores wall-clock dates.

## Calendar projections

A tick is *projected* onto a culture's calendar for human-readable output. Built: the
**Tharadi** calendar from Vaeris canon — 2 seasons (Sandstorm/Oasis), 8 god-dedicated
months, a 6-day week. Days-per-month (30) and hours-per-day (24) are chosen defaults; the
canon fixes the season/month/week structure but not those.

## Multiple coexisting calendars  🟡

Vaeris runs the Tharadi 8-month year *and* the Aetherian 10-month year with a known offset
and dual-dating on contracts. So the sim keeps **one canonical clock** and each culture
supplies a *projection*. Cross-calendar event anchoring is canon ("Ra'anis begins six days
after Vidoris"; the imperial line founded ~Year 220 of the Aetherian calendar).

## Adaptive granularity  ⚪

Tick resolution should vary with **narrative density** — minutes inside a scene, weeks
across dull travel. The story director (which knows where the interesting beats are) sets
the clock. A coarse fast-forward must store boundary state + a seed so fine texture can be
*reconstructed* later without contradiction — compressed time, not discarded time.

## Simulation speed  ⚪

A playback dial from realtime (watch someone knead dough) up to months/second. Slowing
forces fine texture to **materialize**; speeding just streams the coarse skeleton. Couples
to LOD — only the agents you're attending to run at full fidelity.

**Code:** `app/Sim/Time/{TharadiCalendar, TharadiDate}`.
