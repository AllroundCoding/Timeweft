# 08 · Story Direction & Generation  🟢 built (director + two-direction generation)

## Milestones — top-down steering  🟢

An authored beat with a deadline + a prerequisite. Once the prerequisite is met, the
probability of it resolving **ramps with urgency** toward the deadline; if the organic path
stalls, a **bridging event forces** it at the deadline. Built: the trading-post milestone,
verified to land both organically (population grew past the prereq) and forced (small
population that never reached it).

## The author's hand

**Soft by default** — the sim surfaces a conflict and the author chooses — with optional
**hard pins** ("sacred" beats the rebuild must route around). The same dial governs milestone
steering, pacing, and ripple-conflict resolution ([09](09-causality-editing-ripple.md)).

## Authored end-state generation  🟢

Authors usually imagine a world *already further along* (Vaeris has living empires and ruined
ones). So generation is a **boundary-value** problem, not just forward-from-seed:

1. Authored facts become **waypoints** across the timeline (mostly at "now"), each with a
   temporal tolerance.
2. **Backward-decompose** each into preconditions (empire → founding kingdom → consolidated
   tribes → population) to lay a sparse scaffold of must-happen events, topologically ordered.
3. The emergent forward sim fills texture in the gaps while the director **guarantees each
   waypoint lands** within tolerance.

The backward decomposition uses the **same causal-precondition graph** as ripple — generation
runs it backward, ripple runs it forward ([09](09-causality-editing-ripple.md)).

## Consequences

- **Lore consistency-checker** — unsatisfiable authored facts (two empires owning one region
  at once; a kingdom predating its founders) are flaggable conflicts.
- **"Naturally" is bought with slack** — more lead time → organic convergence; tight
  constraints force unnatural bridges. The time-budget dial *is* the naturalness dial.
- **Fallen empires** are sub-arcs that rise *and* end before "now" — and the cohesion/
  institution ratchet ([07](07-cooperation-projects-institutions.md)) produces the fall for
  free; the director just times it so the ruins are cold by the present.

**Two modes:** seed-forward ("surprise me") and end-state-backward ("justify my Vaeris") —
same machinery, opposite directions.

**Code:** `app/Sim/Direction/{Milestone, StoryDirector}`.
