<?php

namespace App\Sim\Causality;

/**
 * An edit to the past — the thing a retroactive ripple replays the world with
 * (design doc 09). v1 expresses the canonical "undo a disaster" edit: suppress a
 * shock that history recorded ("what if the blight of Year 1 had never struck?").
 *
 * Crucially, suppression nullifies a shock's *effect* without skipping the RNG
 * draws that produced it, so the seeded stream stays aligned and the counterfactual
 * diverges only through the edit's real causal consequences — a legible ripple,
 * not butterfly chaos.
 */
final class Intervention
{
    /**
     * @param  ?int  $year  the in-world year to act on; null = any year
     * @param  ?string  $shockType  'blight' | 'raid' | 'plague'; null = any shock
     */
    private function __construct(
        public readonly ?int $year,
        public readonly ?string $shockType,
    ) {}

    /** Undo a shock from the record: a given year and/or type, or every shock when both are null. */
    public static function suppressShocks(?int $year = null, ?string $shockType = null): self
    {
        return new self($year, $shockType);
    }

    public function suppressesShock(int $year, string $type): bool
    {
        return ($this->year === null || $this->year === $year)
            && ($this->shockType === null || $this->shockType === $type);
    }
}
