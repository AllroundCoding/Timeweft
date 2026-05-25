<?php

namespace App\Sim\Worldgen;

/**
 * The climate surface (design doc 13; TWT-132) derived from the frozen {@see Substrate}: per-cell
 * temperature, precipitation, agrarian fertility, and a coarse {@see Biome}. This is the layer the
 * culture/economy model reads — fertility is the agrarian surplus that sets carrying capacity and feeds
 * hierarchy. A first pass: temperature from latitude + elevation, precipitation from latitude bands +
 * orographic rain-shadow, fertility from the two. Climate zones, cryosphere, soil-from-rock, and disease
 * are deferred (TWT-132).
 *
 * Immutable and grid-indexed [y][x]; a pure function of the substrate, so the same seed yields the same
 * climate.
 */
readonly class Climate
{
    /**
     * @param  list<list<float>>  $temperature  °C per cell (sea-surface or land-surface)
     * @param  list<list<float>>  $precipitation  moisture index 0..1 per cell
     * @param  list<list<float>>  $fertility  agrarian potential 0..1 per cell (0 over water and ice)
     * @param  list<list<Biome>>  $biome  the biome each cell wears
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $temperature,
        public array $precipitation,
        public array $fertility,
        public array $biome,
    ) {}

    public function temperatureAt(int $x, int $y): float
    {
        return $this->temperature[$y][$x];
    }

    public function precipitationAt(int $x, int $y): float
    {
        return $this->precipitation[$y][$x];
    }

    public function fertilityAt(int $x, int $y): float
    {
        return $this->fertility[$y][$x];
    }

    public function biomeAt(int $x, int $y): Biome
    {
        return $this->biome[$y][$x];
    }
}
