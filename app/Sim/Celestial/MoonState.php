<?php

namespace App\Sim\Celestial;

/**
 * A moon's projected appearance at a given tick — where it is in its phase cycle and how much of its
 * face is lit. Derived texture (a pure function of the tick), never stored.
 */
final readonly class MoonState
{
    public function __construct(
        public string $name,
        /** Position in the phase cycle, 0..1: 0 = new, 0.5 = full. */
        public float $phase,
        /** Lit fraction of the disc, 0 (new) .. 1 (full). */
        public float $illumination,
        /** The named phase: New, Waxing Crescent, First Quarter, Waxing Gibbous, Full, … */
        public string $phaseName,
    ) {}
}
