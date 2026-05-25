# 07 · Cooperation, Projects & Institutions  🟢 built (projects, institutions, rise & fall)

## Projects — bottom-up steering  🟢

A group works toward a shared goal by a deadline; each member contributes effort by a
**participation weight**. This is the story-director pattern ([08](08-direction-and-generation.md))
democratized — the goal originates *in-world* (an agent, a need, or a cultural norm) instead
of from the author.

Built: the recurring communal **Sandstorm preparation** — opens at the new year, deadline at
the first Sandstorm month, adults contribute `cohesion × sociability/100`, outcome-by-degree
is a readiness ratio vs required effort (scaled per-capita).

Initiation sources: individual-initiated (a birthday), environment/need-triggered (storm
prep), culture-mandated (communal child-rearing).

## Cohesion

Organic cooperation strength. Culture sets the **baseline** (communal high, selfish low); it
**decays with scale** (village high, big city low) and varies per relationship/edge (allied
villages high, rivals near zero). Three axes of *why anyone helps*:

`want-to (culture × cohesion)  ·  paid-to (money)  ·  forced-to (force / fear)`

## Institutions emerge from the cohesion gap  🟢 emergence built

As a settlement grows, organic cooperation can't meet demand — Sunwell already shows this
(chronic storm-underpreparedness as it outgrows its adult workforce). A persistent
**cooperation deficit** triggers an **institution** (guild, taxes, conscription, temple) that
supplies the paid-to/forced-to cooperation organic cohesion no longer can. Culture picks the
*type* → cultural divergence (Tharados → Ra'an's priesthood + emperor; communal Elenwood →
councils).

Built: after a few storms catch Sunwell short, it founds the **Temple of Nara** (Tharados'
desert piety → faith as cooperation technology), which lifts each adult's participation toward
full effort — relieving the deficit until further growth outpaces it again. Culture-by-region
stands in for the culture vector (11) until that lands; the institution's *type* is picked from
the region.

## Complexity ratchet → rise & fall  🟢

Institution raises capacity → bigger projects → bigger settlement → thinner cohesion → demands
*more* institutions (climbs on its own). But institutions **cost** (upkeep) and **ossify**
(extract without delivering); when overhead outweighs return → **collapse** to smaller,
higher-cohesion units → cycle restarts. Rise-and-fall of civilizations, for free.

Repeated, improving projects also crystallize into institutions ("do better next time" =
cultural/tech accumulation) — the micro-mechanism that grows individuals → tribes → kingdoms.

**Code:** `app/Sim/Projects/{Project, ProjectEngine}`, `app/Sim/Institutions/Institution`, `Village.cohesion()`.
