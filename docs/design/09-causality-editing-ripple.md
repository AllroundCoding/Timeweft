# 09 · Causality, Editing & Ripple  🟢 built (provenance graph, retroactive ripple, undo/redo)

## The timeline is a causal graph

Events carry their **causal preconditions / provenance**. This one graph powers three things:

- run **forward** → emergence;
- run **forward from an inserted edit** → retroactive ripple;
- run **backward** → generation from an authored end-state ([08](08-direction-and-generation.md)).

## Retroactive ripple

Insert or edit an event → invalidate only its **downstream causal cone** (not the whole
world) → recompute it. Seeded determinism keeps the rebuild **legible**: anything causally
independent reproduces identically, so only what the edit truly touched diverges — a clean
"history diff", not butterfly chaos. This reframes the cost as the feature: a **counterfactual
"what-if" sandbox**.

> Canon test case: undo the mage **Lyrion's diversion of the Great Flood** → the Silver Desert
> never forms → the buried green border zone survives and the stranded villages stay Aetherian
> → trade routes redraw. The downstream cone, made visible.

## Undo & non-destructive editing

Editing is **non-destructive** but the world is *recomputed*, not folded: an edit is applied as an
**intervention** and the world is rebuilt by seeded replay from the nearest checkpoint with that
intervention in effect, recomputing only the affected downstream cone. Two distinct histories:

- the **in-world** timeline (year 590, 612…);
- the **edit log** — the sequence of authoring actions (the one genuinely append-only
  structure; undo operates here, in real time).

**Selective undo** — removing an old event with later edits stacked on top — is *ripple +
rebase*: pull the node, replay dependent edits onto a world where it never happened (like
git-reverting a middle commit). Same engine as ripple.

## Provenance must be coarse

Record causal links only for **tracked agents / canonical events / institutions** — never
cohort texture — or an early event's cone explodes to "everything." The LOD line
([10](10-persistence-lod-roadmap.md)) is the guardrail against the "everything impacts
everything" trap.
