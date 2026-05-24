# 14 · The Biographical Layer - Individual Lives, Relationships & Drama  ⚪ future (new milestone area)

Timeweft models civilizations richly and individuals thinly. The macro layer - geography, economy,
culture, faith, institutions, dynasties, war (docs 06-13, and the Deep Realism systems) - is dense.
The *micro, biographical* layer that turns a demographic record into individual lives and stories is
sparse. Today an agent ([`App\Sim\World\Agent`](../../app/Sim/World/Agent.php)) has traits, needs, a
partner, parents, a job, and a death. It has no friends or rivals, no reputation, no ambitions of its
own, and no capacity to transgress.

This doc sketches the layer that gives individuals an inner and social life. It is the **individual**
scale, complementing the **collective / societal** systems (kinship, stratification, ethnogenesis,
collective memory) - those describe groups; this describes persons. It is also the layer the player
inhabits in the game phase ([doc 16](ROADMAP.md)).

## The discipline: reuse, don't invent

As with [doc 12](12-goods-tastes-tech-conflict.md), the whole sprawl reduces to primitives the engine
already has. Each system below is an existing mechanism applied at the individual scale:

| System | Reuses | One scale down from |
|---|---|---|
| **Relationships** | standing + warranted-drift | `RelationsEngine` (inter-settlement → inter-person) |
| **Reputation & renown** | the causal chronicle | the provenance graph (events → deeds) |
| **Personal ambitions** | steer-toward-a-goal-on-a-budget | `StoryDirector` / `Project` (world/group → person) |
| **Crime & justice** | institution-from-a-deficit | `InstitutionEngine` (cooperation deficit → trust deficit) |

## 1 · Interpersonal relationships  ⚪

The agent-scale social graph: dyadic bonds (friend / rival / mentor / grudge) that form and decay
from shared activity, proximity, kinship, personality (OCEAN), and conflict - the same
warranted-standing + drift math as the inter-settlement [`RelationsEngine`](../../app/Sim/World/RelationsEngine.php),
one scale down. Bonds bias what already exists: project participation, mutual aid, pairing, migration.
The substrate the other three read.

## 2 · Reputation & renown  ⚪

Deeds - already recorded as chronicle events - accrue a living reputation that grants social weight
(leadership, recruitment, marriage desirability) and decays with obscurity. At death it seeds the
legend generator (doc 11 / Deep Realism) and collective memory. The living complement to post-hoc
mythology.

## 3 · Personal ambitions  ⚪

The missing third steering mode. Top-down is the story director ([doc 08](08-direction-and-generation.md));
group bottom-up is projects ([doc 07](07-cooperation-projects-institutions.md)); this is *individual*
bottom-up - an agent pursuing a self-authored goal on a time budget (rank, wealth, a partner, revenge,
mastery), seeded from personality and circumstance, biasing the behavior priority stack
([doc 04](04-behavior-and-resolution.md)). The player's verb set, and what makes NPCs legible rivals
for the same goals.

## 4 · Crime, transgression & justice  ⚪

Agents can transgress when motive (need, blocked ambition, grudge) beats restraint (conscientiousness,
faith adherence, fear of sanction). The response escalates from informal sanction (reputation /
relationship hits, exile) to a *justice institution* - law, courts - born of a **trust deficit**
exactly as the Temple is born of a **cooperation deficit** ([doc 07](07-cooperation-projects-institutions.md)).
Legal culture (restitution vs retribution, severity) derives from the culture vector
([doc 11](11-cultural-and-social-model.md)), so cultures diverge.

They compound: relationships set the stakes, ambitions the motives, reputation the currency; crime is
what happens when a motive meets a trust deficit - emergent personal drama on top of the emergent
history.

## Adjacent enrichments (second tier)

Hanging off this layer and the culture / economy core: **knowledge institutions** (schools / libraries
that make the tech tree sticky), **art, craft mastery & wonders** (named masterworks, signature styles,
monuments), **pilgrimage & sacred geography**, **medicine & healers** (the care response to disease),
**life-stages & rites of passage**, and **emergent festivals** (holidays born from history and pantheon).

## Determinism (the two-zone rule still holds)

All of this lives in the pure [`app/Sim`](../../app/Sim) core. RNG only through
[`App\Sim\Support\Rng`](../../app/Sim/Support/Rng.php) forked sub-streams, so adding a person's bonds or
ambitions never perturbs the seeded macro draws. New persistent state (a bond, a reputation score, an
open ambition, a crime record) is *skeleton*; transient evaluation is *texture* ([doc 01](01-architecture.md)).
Provenance stays coarse - tracked agents only, never cohort texture ([doc 09](09-causality-editing-ripple.md),
[doc 10](10-persistence-lod-roadmap.md)) - or an early bond's downstream cone explodes. Behavior-changing
systems re-baseline the canonical narrative on purpose; golden tests assert invariants, not snapshots.

## Status

⚪ Future. Epic [TWT-212](https://linear.app/allroundcoding/issue/TWT-212); tasks TWT-214 (relationships),
TWT-215 (reputation), TWT-216 (ambitions), TWT-217 (crime & justice); second tier TWT-218-223. Builds on
agents ([03](03-agents-traits-needs.md)), behavior ([04](04-behavior-and-resolution.md)),
cooperation / institutions ([07](07-cooperation-projects-institutions.md)), direction
([08](08-direction-and-generation.md)), and the cultural model ([11](11-cultural-and-social-model.md)).
Captured 2026-05-24 from a gap-analysis brainstorm - expand and refine as it firms up.
