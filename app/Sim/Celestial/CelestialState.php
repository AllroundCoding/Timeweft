<?php

namespace App\Sim\Celestial;

/**
 * The sky at one tick — the sun's orbital position and the moons' phases, plus the tides and notable
 * alignments that follow from them. Entirely derived from the tick ({@see CelestialAlmanac::forTick()});
 * texture, never stored.
 */
final readonly class CelestialState
{
    /**
     * @param  list<MoonState>  $moons  the moons, in the world's fixed order
     * @param  list<string>  $alignments  notable syzygies in effect (conjunctions, oppositions, moon pairings)
     */
    public function __construct(
        public int $tick,
        /** Position in the orbit, 0..1, measured from the new year. */
        public float $yearFraction,
        /** The sun's ecliptic longitude in radians, 0..2π. */
        public float $solarLongitude,
        /** The sun's declination in degrees (its latitude over the world): 0 at equinox, ±axial tilt at solstice. */
        public float $solarDeclination,
        /** Distance to the sun relative to the mean (1.0); below 1 near perihelion, above near aphelion. */
        public float $solarDistance,
        public array $moons,
        /** Combined tidal amplitude, 0 (neap) .. 1 (spring). */
        public float $tideLevel,
        /** Whether sun and moon(s) are aligned enough for a spring tide. */
        public bool $springTide,
        public array $alignments,
    ) {}

    public function moon(string $name): ?MoonState
    {
        foreach ($this->moons as $moon) {
            if ($moon->name === $name) {
                return $moon;
            }
        }

        return null;
    }
}
