---
title: Vaeris — The Realism Loop & the Magic Carve-Out
summary: How authored Vaeris lore is checked for internal realism against Timeweft's generate-from-materials model, and the deliberate exception for magic — including the `magic_exception` annotation convention.
status: authored
---

# The Realism Loop & the Magic Carve-Out

Vaeris is authored *and* meant to be plausible: a world a simulator could have produced. This page
explains the check that keeps it honest — and the one deliberate exception, magic.

## The realism loop

[Timeweft](https://github.com/allroundcoding/timeweft) generates a culture **from its material
conditions** (cultural materialism): geography and climate set scarcity, hazards, and carrying
capacity; those shape the economy; the economy shapes social structure; and that shapes values
and faith. Vaeris runs the same idea as a *check* on authored lore:

- Each region's [`canon/regions/*.yaml`](../canon/regions/) records an **`expected_culture`** — the
  values the materialist model *should* produce for that environment.
- Where the authored lore diverges from that prediction, the file records a **`tension`**.
- A tension is a prompt, not a verdict: either the **lore** is off and should be corrected, or
  there's a **real in-world cause** the materialist model doesn't capture. Resolve each one
  (Aetheria's were resolved under WRL-23/WRL-24) — *except* where the cause is magic.

## The default rule

> A fact about a society should plausibly arise from its material conditions.

If the lore asserts something the materials wouldn't produce, that's a tension to resolve. Reach
for a material explanation **first** — most apparent exceptions have one (Aetheria's feudal
hierarchy, below, is really agrarian land-tenure; Nyralthia's piety is really hazard-driven;
Elenwood's restraint is really ecological stewardship).

## The magic carve-out

Vaeris is a world where **magic is real** (see the [world overview](world/overview.md#magic)):
graded crystals, elemental and nature and healing and arcane magic, divination, divine artifacts,
and gods who may act in the world. So some facts are **legitimately exempt** from a purely
materialist explanation — not because we're hand-waving a problem away, but because magic is the
actual in-world cause.

The carve-out is **narrow and must be justified**:

1. **Name the mechanism.** You may exempt a fact only when you can state the magical cause. "Magic
   did it" with no mechanism is not a carve-out — it's a tension in hiding.
2. **Materialism first.** Use a carve-out only when magic genuinely is the cause, and ideally when
   the lore already establishes that magic. If a material reading covers the fact, prefer it.
3. **Cultural facts, not physical impossibilities.** A carve-out can explain a *disposition*
   (piety holding under prosperity). It does not license physically impossible *material* claims
   (a waterless desert feeding millions) unless magic specifically supplies the mechanism — and
   then you still name it.

## The `magic_exception` convention

Mark exempt facts in canon so the realism audit can skip them. In a region's `expected_culture`,
add a `magic_exceptions:` list; each entry has:

```yaml
magic_exceptions:
  - exempt: piety            # the axis or fact exempted
    mechanism: >-           # the in-world magical cause that justifies it
      Why magic, specifically, produces this fact.
    # note: optional extra context
```

Facts under `magic_exceptions` are **not** `tensions` — keep genuine, materially-resolvable
divergences in `tensions`, and move a fact to `magic_exceptions` only once it's accepted as a
magical exemption. The realism audit (WRL-22) treats `magic_exceptions` as resolved.

## Worked examples

- **Aetheria — piety (a carve-out).** A prosperous, secure kingdom stays broadly devout, where
  materialism predicts survival-piety erodes with plenty. Magic is the cause: gods tied to a
  working calendar, enchanted sites, and divine artifacts make devotion *evidence-based*. Applied
  in [`canon/regions/aetheria.yaml`](../canon/regions/aetheria.yaml) as the first `magic_exception`.
- **Aetheria — hierarchy (a material resolution, not a carve-out).** Feudal hierarchy in a fertile
  land *looks* like a tension, but it's materially grounded: hierarchy tracks surplus extraction
  and land-tenure, not scarcity, and feudalism is an agrarian-surplus institution. Resolved in
  canon (WRL-23) by documenting the cause — no magic needed. The contrast with piety, which *does*
  need the carve-out, is the point.
- **Elenwood — animism.** The forest spirits are literal, so animism is grounded in fact. (Note,
  though, that Elenwood's *restraint* is better read materially — stewardship of a fragile forest
  — not as magic.)
- **Nyralthia — mostly material.** Its high piety looks like a carve-out but mostly isn't: storms
  and volcanism are real, frequent, deadly hazards, so propitiating sea and storm gods is
  rational. Magic (its regulated crystal-craft) covers only the residue. The textbook reminder
  that a carve-out is a *last* resort.

## In short

Check every authored fact against the materials. Fix or explain the divergences. Where the honest
explanation is "because magic," say *which* magic, mark it `magic_exception`, and move on.
