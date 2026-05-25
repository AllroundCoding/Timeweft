<?php

namespace App\Sim\Worldgen;

use App\Sim\Support\Rng;

/**
 * Derives the solid-earth substrate from a handful of plate seeds (design doc 13; TWT-130).
 *
 * The chain: scatter plates → each cell belongs to its nearest plate (a coarse plate graph, Voronoi);
 * continental crust floats high, oceanic sits low → near a plate boundary the relative drift of the
 * two plates lifts the crust where they converge (mountains, the orogeny that pools ore and gems) and
 * drops it where they pull apart (rifts) → the resulting field is the DEM, its sub-sea part the
 * bathymetry. LOD-adjustable: a small grid and a few plates suffice to start.
 *
 * Deterministic and framework-free: every authored seed is drawn from a per-plate sub-stream of the
 * supplied {@see Rng}, so the same seed reproduces the same world and adding a plate can't perturb the
 * others.
 */
final class SubstrateGenerator
{
    /** Share of plates that are continental (float high); the rest are ocean floor. */
    private const CONTINENTAL_FRACTION = 0.45;

    private const CONTINENTAL_BASE = 0.25; // sea-level-relative resting height of continental crust

    private const OCEANIC_BASE = -0.6; // oceanic crust sits below the waterline

    private const UPLIFT_SCALE = 1.2; // how strongly convergence lifts (or divergence drops) the boundary

    private const BOUNDARY_BAND = 0.08; // width of the deformed zone, as a fraction of the smaller map side

    private const RELIEF_AMPLITUDE = 0.18; // height of the fractal relief layered onto the flat crust (TWT-262)

    private const RELIEF_FREQUENCY = 0.05; // base spatial frequency of that relief, in cells⁻¹

    public static function generate(Rng $rng, int $width = 64, int $height = 48, int $plateCount = 8): Substrate
    {
        $plates = [];
        for ($i = 0; $i < $plateCount; $i++) {
            $seed = $rng->stream('plate', $i);
            $plates[] = new Plate(
                id: $i,
                x: $seed->float(0.0, $width),
                y: $seed->float(0.0, $height),
                continental: $seed->chance(self::CONTINENTAL_FRACTION),
                driftX: $seed->float(-1.0, 1.0),
                driftY: $seed->float(-1.0, 1.0),
            );
        }

        $band = max(1.0, min($width, $height) * self::BOUNDARY_BAND);
        $relief = new FractalNoise($rng->stream('relief', 0)->int(0, 2_000_000_000), self::RELIEF_FREQUENCY);
        $elevation = [];
        $plateId = [];
        $minerals = [];

        for ($y = 0; $y < $height; $y++) {
            $elevation[$y] = [];
            $plateId[$y] = [];
            $minerals[$y] = [];
            for ($x = 0; $x < $width; $x++) {
                [$near, $nearDist, $next, $nextDist] = self::twoNearest($plates, $x, $y);

                $base = $near->continental ? self::CONTINENTAL_BASE : self::OCEANIC_BASE;
                // How close the cell sits to the boundary between its plate and the next nearest one.
                $boundary = max(0.0, 1.0 - ($nextDist - $nearDist) / $band);
                $tectonic = self::convergence($near, $next) * $boundary * self::UPLIFT_SCALE;

                $elevation[$y][$x] = $base + $tectonic + self::RELIEF_AMPLITUDE * $relief->fbm((float) $x, (float) $y);
                $plateId[$y][$x] = $near->id;
                $minerals[$y][$x] = min(1.0, abs($tectonic)); // ore pools where the crust is worked
            }
        }

        $elevation = HydraulicErosion::erode($elevation, $width, $height); // rivers carve the relief into valleys

        return new Substrate($width, $height, $elevation, $plateId, $minerals, $plates);
    }

    /**
     * The nearest and second-nearest plate to a cell, with their distances.
     *
     * @param  list<Plate>  $plates
     * @return array{0: Plate, 1: float, 2: Plate, 3: float}
     */
    private static function twoNearest(array $plates, int $x, int $y): array
    {
        $near = $plates[0];
        $nearDist = INF;
        $next = $plates[0];
        $nextDist = INF;
        foreach ($plates as $plate) {
            $distance = hypot($plate->x - $x, $plate->y - $y);
            if ($distance < $nearDist) {
                $next = $near;
                $nextDist = $nearDist;
                $near = $plate;
                $nearDist = $distance;
            } elseif ($distance < $nextDist) {
                $next = $plate;
                $nextDist = $distance;
            }
        }

        return [$near, $nearDist, $next, $nextDist];
    }

    /** Positive when two plates drive toward each other (uplift), negative when they pull apart (rift). */
    private static function convergence(Plate $a, Plate $b): float
    {
        $dx = $b->x - $a->x;
        $dy = $b->y - $a->y;
        $length = hypot($dx, $dy);
        if ($length <= 0.0) {
            return 0.0;
        }

        // Relative drift projected onto the axis between the two plate seeds.
        return (($a->driftX - $b->driftX) * $dx + ($a->driftY - $b->driftY) * $dy) / $length;
    }
}
