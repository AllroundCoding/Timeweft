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
    /** Share of plate seeds that are continental (the rest are ocean floor). Raise for more land, lower for more sea. */
    private const CONTINENTAL_FRACTION = 0.55; // 0.45

    /** Resting height of continental crust (sea level = 0). Raise to lift the land table — more, higher land. */
    private const CONTINENTAL_BASE = 0.2; // 0.25

    /** Resting depth of oceanic crust, below the waterline. Lower to deepen the oceans. */
    private const OCEANIC_BASE = -1.6; // -0.6

    /** How strongly converging plates lift (and diverging plates drop) the crust. Raise for taller mountains and deeper rifts. */
    private const UPLIFT_SCALE = 1.7; // 1.2

    /** Width of the deformed zone along a plate boundary, as a fraction of the smaller map side. Raise for broad, gentle ranges; lower for narrow, sharp ones. */
    private const BOUNDARY_BAND = 0.12; // 0.08

    /** Height of the fractal relief layered onto the crust (TWT-262). Raise for hillier, rougher land and craggier coasts; lower for smoother terrain. */
    private const RELIEF_AMPLITUDE = 0.24; // 0.18

    /** Base spatial frequency of that relief (cells⁻¹). Raise for many small hills; lower for fewer, broader landforms. */
    private const RELIEF_FREQUENCY = 0.07; // 0.05

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
