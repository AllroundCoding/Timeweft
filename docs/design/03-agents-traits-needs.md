# 03 · Agents, Traits & Needs  🟢 built (core) · 🟡 (dispositions, special entities)

## Composable traits

An agent is a **bag of traits + needs**, not a fixed schema — "all the way down to
allergies and preferences." Traits layer in order:

`species template (base ranges) → region (overrides/modifiers) → culture/faith (dispositions) → individual roll → inheritance (from parents)`

Built: the **Vulpini** species (agility, senses, dexterity, constitution, sociability) +
the **Tharados** region (constitution/senses modifiers, a desert fur palette, derived heat
tolerance). Children inherit each trait as `f(parentA, parentB) + seeded mutation`.

## Needs

Composable **need components** (hunger, energy) with per-tick drift, satisfied by
activities. Needs are *attached*, not hardcoded — which is precisely how special entities
differ.

## Special entities — the "god among men"  🟡

A god incarnate mimics ordinary behavior but lacks biological need-drivers: model it by
simply *omitting* those need components. Canon archetype: the Istari (Maiar in old men's
bodies) — Gandalf acts mortal but isn't.

## Economic & social dispositions  🟡

Generosity/stinginess, thrift/spending (saver vs spender), hoarding — all **traits**,
modulated by culture and **faith tenets** (Nara's charity raises giving; Ra'an's austerity
cuts consumption). "Everyone handles money differently" = per-agent disposition traits
nudged by belief. Feeds the economy ([06](06-resources-economy-trade.md)) and cooperation
([07](07-cooperation-projects-institutions.md)).

## Scale-polymorphism

The same trait / need / lifecycle structure applies to households, villages, kingdoms, and
religions at different LOD — see [07](07-cooperation-projects-institutions.md) and
[10](10-persistence-lod-roadmap.md).

**Code:** `app/Sim/World/{Agent, Need, Species, RegionProfile}`, `app/Sim/Support/{Rng, TharadiNameGenerator}`.
