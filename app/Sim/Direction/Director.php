<?php

namespace App\Sim\Direction;

use App\Sim\Time\TharadiDate;
use App\Sim\World\World;

/**
 * The narrative author / game-master (design doc 15; TWT-89), generalized and made pluggable. A
 * director's job is **interest**, not validity (that's the world guider, TWT-90): it steers the world
 * toward the lore you want by authoring *inputs* — pins, milestones, root events — and lets the
 * deterministic engine + causal graph propagate the *outcomes*. The world runs perfectly well without
 * one ({@see NullDirector}): a director only layers narrative intent over pure seeded emergence.
 *
 * The product stance is three interchangeable implementations behind this one seam:
 *   - **no LLM** — a human sets pins/milestones/events directly; the rule-based {@see RuleDirector}
 *     evaluates them. The reproducible default, and what the engine has always run.
 *   - **built-in LLM** and **bring-your-own LLM** — an LLM authors the inputs instead of a human.
 *     Same interface, same "author inputs, not outcomes" contract; deferred to the M7 flavor layer
 *     so the deterministic core stays LLM-free.
 *
 * Whoever directs authors only inputs, so every intervention is a canonical, logged edit and the
 * author's hand stays visible — between interventions, pure emergence.
 */
interface Director
{
    /** Author this day's narrative inputs; the engine and causal graph propagate the outcomes. */
    public function direct(World $world, int $tick, TharadiDate $date): void;
}
