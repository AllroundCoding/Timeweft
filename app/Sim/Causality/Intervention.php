<?php

namespace App\Sim\Causality;

/**
 * An edit to the past — the thing a retroactive ripple replays the world with
 * (design doc 09). v1 expresses the canonical "undo a disaster" edit: suppress
 * one or more shocks the record holds ("what if the blight of Year 1 had never
 * struck?"). Several edits compose into one via {@see anyOf}, so a whole edit
 * log folds into a single intervention.
 *
 * Crucially, suppression nullifies a shock's *effect* without skipping the RNG
 * draws that produced it, so the seeded stream stays aligned and the
 * counterfactual diverges only through the edit's real causal consequences — a
 * legible ripple, not butterfly chaos.
 */
final class Intervention
{
    /** @param list<array{year:?int,type:?string}> $rules each rule suppresses a matching shock */
    private function __construct(private readonly array $rules) {}

    /** Undo a shock from the record: a given year and/or type, or every shock when both are null. */
    public static function suppressShocks(?int $year = null, ?string $shockType = null): self
    {
        return new self([['year' => $year, 'type' => $shockType]]);
    }

    /** The empty edit — changes nothing (an edit log with no active edits). */
    public static function none(): self
    {
        return new self([]);
    }

    /** Compose edits: the result suppresses a shock if *any* constituent edit does. */
    public static function anyOf(self ...$interventions): self
    {
        $rules = [];
        foreach ($interventions as $intervention) {
            foreach ($intervention->rules as $rule) {
                $rules[] = $rule;
            }
        }

        return new self($rules);
    }

    public function suppressesShock(int $year, string $type): bool
    {
        foreach ($this->rules as $rule) {
            if (($rule['year'] === null || $rule['year'] === $year)
                && ($rule['type'] === null || $rule['type'] === $type)) {
                return true;
            }
        }

        return false;
    }
}
