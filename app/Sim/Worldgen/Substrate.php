<?php

namespace App\Sim\Worldgen;

/**
 * The solid-earth substrate (design doc 13; TWT-130) — the bottom of the map stack, generated once
 * from plate seeds and then frozen. It carries the layers everything upward reads: a sea-level-relative
 * elevation field (the DEM, negative below the waterline = bathymetry), which plate owns each cell, and
 * a mineral/gem concentration that pools where the crust is tectonically active (mountains and rifts).
 * Slope is a DEM derivative, computed on demand for the travel-cost surface and defensibility.
 *
 * Immutable and grid-indexed [y][x]; a pure function of its seed, so the same seed yields the same world.
 */
readonly class Substrate
{
    /**
     * @param  list<list<float>>  $elevation  sea-level-relative height per cell (>0 land, <0 sea floor)
     * @param  list<list<int>>  $plateId  which plate owns each cell
     * @param  list<list<float>>  $minerals  ore/gem concentration 0..1 per cell
     * @param  list<Plate>  $plates  the seed plates this was derived from
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $elevation,
        public array $plateId,
        public array $minerals,
        public array $plates,
    ) {}

    public function elevationAt(int $x, int $y): float
    {
        return $this->elevation[$y][$x];
    }

    public function isLand(int $x, int $y): bool
    {
        return $this->elevation[$y][$x] > 0.0;
    }

    public function mineralAt(int $x, int $y): float
    {
        return $this->minerals[$y][$x];
    }

    /**
     * Local steepness — the largest elevation drop to a four-neighbour, the input to travel cost and
     * defensibility. Longitude wraps around the globe; latitude is capped at the poles.
     */
    public function slopeAt(int $x, int $y): float
    {
        $here = $this->elevation[$y][$x];
        $slope = 0.0;
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
            $ny = $y + $dy;
            if ($ny < 0 || $ny >= $this->height) {
                continue; // latitude is capped at the poles
            }
            $nx = ($x + $dx + $this->width) % $this->width; // longitude wraps around the globe
            $slope = max($slope, abs($here - $this->elevation[$ny][$nx]));
        }

        return $slope;
    }

    /** Fraction of the map above the waterline — a sanity check on a generated world. */
    public function landFraction(): float
    {
        $land = 0;
        foreach ($this->elevation as $row) {
            foreach ($row as $value) {
                if ($value > 0.0) {
                    $land++;
                }
            }
        }

        return $land / max(1, $this->width * $this->height);
    }

    public function highestElevation(): float
    {
        $highest = -INF;
        foreach ($this->elevation as $row) {
            $highest = max($highest, ...$row);
        }

        return $highest;
    }
}
