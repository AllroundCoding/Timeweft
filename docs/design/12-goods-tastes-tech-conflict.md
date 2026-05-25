# 12 · Goods, Tastes, Technology & Conflict  🟡 goods partly built; tastes / technology / conflict future

This extends the economy ([06](06-resources-economy-trade.md)) and cultural model
([11](11-cultural-and-social-model.md)) from **scalar resources** to **itemized goods with
stats**, and projects the rise-and-fall ([07](07-cooperation-projects-institutions.md))
*outward* — from a settlement collapsing under its own institutions to a kingdom losing a war.
It is captured from a brainstorm; the point of this doc is that the whole sprawl reduces to the
same four primitives we already use.

## The loop

It closes rather than sprawls. Each arrow is an existing mechanism:

`region → goods (supply) ⇄ tastes (demand) → trade → wealth → tech → strength → war → reshapes regions →` (back to the top)

## Goods have stats (items as trait-vectors)

Today a stockpile is a `name → quantity` map; food and water are fungible scalars (built in M2).
This makes a **Good** a small **stat vector** — the same composable pattern as an agent's traits
([03](03-agents-traits-needs.md)), defined through the same registry. Base produce carry base
stats (nutrition, value, perishability); **recipes combine them into a meal whose stats exceed
the sum** — a balanced diet beats raw grain. Equipment (armor, tools, weapons) is the same
primitive in the military/economic domain, carrying combat and upkeep stats. Two domains, one
mechanism.

## Regional product generation (the supply side)

**Cultural Materialism (Harris)** applied to goods: what a region produces falls out of its
material conditions — the same generative rule that seeds a culture from its environment
([11](11-cultural-and-social-model.md)). Desert oases → dates, grain, herds; the Mirage Coast →
fish, salt; the Scorching Mountains → ore, gems. Goods are *generated*, not hand-placed.

## Tastes & preferences (the demand side)

A slice of the culture vector ([11](11-cultural-and-social-model.md)) plus personal dispositions
([03](03-agents-traits-needs.md)). The elegant generative hook: **tastes seed from scarcity** —
a people crave what their region lacks and prize the foreign. That single rule *manufactures*
trade demand, turning trade from moving-surplus arbitrage into spice routes, luxury and status
goods. Diet variety and cuisine feed the needs/health stack ([04](04-behavior-and-resolution.md))
and the future disease model.

## Trade (the bridge)

Builds directly on [06](06-resources-economy-trade.md): differential **supply** (regional
generation) × differential **demand** (tastes) → flows and prices, and trade raises a settlement's
*effective* carrying capacity by importing what it cannot grow (Ricardo, comparative advantage).
Trade also diffuses goods, which drifts preferences — cultural diffusion
([11](11-cultural-and-social-model.md)).

## Technology as a tree (generalize the scalar)

In M2 `technology` is a single multiplier on yield. Explode it into discrete advances — the
**Boserup / innovation ratchet** ([11](11-cultural-and-social-model.md)) — unlocked by conditions
(surplus, contact, institutions) and accumulating over time. Some are economic (yield, storage,
irrigation); some are military (armor, weapons), feeding the next section.

## Kingdom strength & war (the external rise-and-fall)

On scale-polymorphic agents ([01](01-architecture.md)): a kingdom *is* an agent, so its **strength
is a derived trait** computed from military tech + population + economy + cohesion. War is
**resolution between two kingdom-agents** — relative strength × a counter-matrix over armor/weapon
types (rock-paper-scissors) × a seeded roll. This upgrades the random raid *shock*
([06](06-resources-economy-trade.md)) into a structured contest. Defeat means territory and
population loss, which can topple institutions ([07](07-cooperation-projects-institutions.md)):
the **external door** to the rise-and-fall, complementing the **internal** one (institutional
ossification) already built in M2.

## Why it stays small

Resisting a bespoke 4X engine is the whole discipline. Every piece is reused machinery:

- items = stat vectors (like traits, [03](03-agents-traits-needs.md));
- regional goods & tastes = generate-from-materials (like culture, [11](11-cultural-and-social-model.md));
- trade & war = resolution between agents ([04](04-behavior-and-resolution.md));
- kingdom strength & war = the rise-and-fall loop ([07](07-cooperation-projects-institutions.md)) at a larger scale.

## Open questions

- **Granularity** — a lightweight "diet quality / variety" attribute, or full itemized recipes?
  Start light; itemize only if it earns its keep. Same question for the tech tree's depth.
- **Legibility** — how many goods/techs before richness becomes noise?
- **Counter-matrix** — authored, or derived from item stats?
- **Determinism** — war outcomes stay seeded and reproducible, like everything else.

## Milestones

- **M9 · Goods, tastes & trade** — itemized goods, regional generation, tastes, nutrition/cuisine,
  deepened trade + pricing.
- **M10 · Technology & conflict** — tech tree, military items, kingdom strength, war resolution.

Both depend on **M6 · Scale** (kingdoms as agents) landing first, and follow the culture layer
(**M8**, doc 11) that gives tastes their vector.

## Status

⚪ Future. Builds on [06](06-resources-economy-trade.md), [11](11-cultural-and-social-model.md),
[07](07-cooperation-projects-institutions.md), and [01](01-architecture.md). Captured from a
brainstorm — expand and refine as it firms up.
