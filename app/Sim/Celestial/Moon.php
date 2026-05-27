<?php

namespace App\Sim\Celestial;

/**
 * A moon as orbital constants — the immutable configuration the {@see CelestialAlmanac} projects off the
 * tick. A world's moons are fixed facts of its sky (not seeded, not stored), so the almanac stays a pure
 * function of the integer clock.
 */
final readonly class Moon
{
    public function __construct(
        public string $name,
        /** Days for one full new → full → new phase cycle (its synodic period). */
        public float $synodicPeriodDays,
        /** Tidal influence relative to the sun's (1.0); a larger, nearer moon pulls harder. */
        public float $tidalPull = 1.0,
        /** Phase position, in days, at tick 0 — so moons don't all start new together. */
        public float $phaseOffsetDays = 0.0,
    ) {}
}
