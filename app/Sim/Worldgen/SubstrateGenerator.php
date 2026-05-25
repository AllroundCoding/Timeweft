<?php

namespace App\Sim\Worldgen;

use App\Sim\Support\Rng;

final class SubstrateGenerator
{
    /** Higher value is more land */
    private const float CONTINENTAL_FRACTION = 0.75; // 0.29 earth-like default

    /** Higher value is higher base landmass */
    private const float CONTINENTAL_BASE = 0.4; // 0.8 earth-like default

    /** Oceanic floor level */
    private const float OCEANIC_BASE = -3.7; // -3.7 earth-like default

    /** How strongly converging plates lift the crust. */
    private const float UPLIFT_SCALE = 1.2; // 4 earth-like default

    /** Width of the deformed zone. Dropped to 2.5% for high-plate-count maps. */
    private const float BOUNDARY_BAND = 0.010; // 0.12 earth-like default

    /** Massive band for wide, sweeping continental slopes */
    private const float SHELF_BAND = 0.12; // 0.35 earth-like default

    /** Noise amplitude, higher value is more noisy terrain */
    private const float RELIEF_AMPLITUDE = 0.24; // 0.24 earth-like default

    /** Base spatial frequency of that relief (cells⁻¹). Raise for many small hills; lower for fewer, broader landforms. */
    private const float RELIEF_FREQUENCY = 0.07; // 0.07 earth-like default

    /** Macro warp breaks the overall straightness of the plates. Values of 0.1 to 0.25 (10-25% of map size) work best. */
    private const float MACRO_WARP_AMPLITUDE_PCT = 0.25; // 20% of map size for huge sweeping curves is default

    /** Low frequency creates large sweeping curves and vast peninsulas. */
    private const float MACRO_WARP_FREQUENCY = 0.0015; // 0.0015 earth-like default

    /** Micro warp adds the local jaggedness and fractal rough edges. */
    private const float MICRO_WARP_AMPLITUDE = 25.0; // 25 earth-like default

    /** High frequency creates rapid, tight variations. */
    private const float MICRO_WARP_FREQUENCY = 0.04; // 0.04 earth-like default

    public static function generate(Rng $rng, int $width = 2560, int $height = 1440, int $plateCount = 70): Substrate
    {
        $plates = [];
        // Define how many plates are massive world-spanning continents/oceans, default is 20%
        $majorPlateCount = max(3, (int)($plateCount * 0.20));

        for ($i = 0; $i < $plateCount; $i++) {
            $seed = $rng->stream('plate', $i);

            // Assign weights based on Major vs Micro status
            $isMajor = $i < $majorPlateCount;
            $weight = $isMajor
                ? $seed->float(2.0, 4.0)   // Major plates swallow huge territory
                : $seed->float(0.2, 0.6);  // Microplates get squished into complex borders

            $plates[] = new Plate(
                id: $i,
                x: $seed->float(0.0, $width),
                y: $seed->float(0.0, $height),
                continental: $seed->chance(self::CONTINENTAL_FRACTION),
                driftX: $seed->float(-1.0, 1.0),
                driftY: $seed->float(-1.0, 1.0),
                weight: $weight
            );
        }

        $band = max(1.0, min($width, $height) * self::BOUNDARY_BAND);
        // Calculate the massive wide band for continental slopes
        $shelfBandDist = max(1.0, min($width, $height) * self::SHELF_BAND);

        $relief = new FractalNoise($rng->stream('relief', 0)->int(0, 2_000_000_000), self::RELIEF_FREQUENCY);
        $macroNoise = new FractalNoise($rng->stream('warp_macro', 0)->int(0, 2_000_000_000), self::MACRO_WARP_FREQUENCY);
        $microNoise = new FractalNoise($rng->stream('warp_micro', 0)->int(0, 2_000_000_000), self::MICRO_WARP_FREQUENCY);
        $macroWarpAmp = min($width, $height) * self::MACRO_WARP_AMPLITUDE_PCT;

        $elevation = [];
        $plateId = [];
        $minerals = [];

        for ($y = 0; $y < $height; $y++) {
            // 1. Calculate Latitude (-1.0 at top/bottom, 0.0 at equator)
            $normalizedY = ($y / ($height - 1)) * 2.0 - 1.0;

            // 2. Calculate the Cosine factor
            // cos(0) = 1 (Equator, no change)
            // cos(PI/2) = 0 (Poles, infinite stretch - so we clamp it)
            $cosLat = cos($normalizedY * (M_PI / 2.0));
            $yScale = max(0.2, $cosLat); // Clamp so we don't get divide-by-zero at poles

            for ($x = 0; $x < $width; $x++) {
                // 3. Scale the Y coordinate based on latitude
                // We use $yScale to "squeeze" the noise so it appears uniform
                $sampledY = ($y - ($height / 2)) / $yScale + ($height / 2);

                // Now use $sampledY in your fbmWrapped calls:
                $rawWarpX = ($macroNoise->fbmWrapped((float)$x, (float)$sampledY, (float)$width) * $macroWarpAmp) +
                    ($microNoise->fbmWrapped((float)$x, (float)$y, (float)$width) * self::MICRO_WARP_AMPLITUDE);

                $rawWarpY = ($macroNoise->fbmWrapped((float)$x + 1000.0, (float)$y + 1000.0, (float)$width) * $macroWarpAmp) +
                    ($microNoise->fbmWrapped((float)$x + 1000.0, (float)$y + 1000.0, (float)$width) * self::MICRO_WARP_AMPLITUDE);

                // 2. Wrap X, but CAP Y to prevent wrapping over the poles
                $warpX = fmod($x + $rawWarpX, $width);
                if ($warpX < 0) {
                    $warpX += $width;
                }

                $warpY = max(0.0, min((float)$height - 1.0, $y + $rawWarpY));

                // 3. Pass the wrapped coordinates to twoNearest
                [$near, $nearDist, $next, $nextDist] = self::twoNearest($plates, $warpX, $warpY, $width);

                // 1. Tectonic Boundary (Narrow, for mountains)
                $boundary = max(0.0, 1.0 - ($nextDist - $nearDist) / $band);

                // 2. Shelf Boundary (Wide, for gradual slopes)
                $shelfBoundary = max(0.0, 1.0 - ($nextDist - $nearDist) / $shelfBandDist);
                // Smooth the slope using a Hermite curve so it eases in and out
                $shelfBlend = $shelfBoundary * $shelfBoundary * (3.0 - 2.0 * $shelfBoundary);

                $convergence = self::convergence($near, $next, $width);

                // 3. Calculate Base with Wide Slopes
                $nearBase = $near->continental ? self::CONTINENTAL_BASE : self::OCEANIC_BASE;
                $nextBase = $next->continental ? self::CONTINENTAL_BASE : self::OCEANIC_BASE;
                // Interpolate so continents gently roll into oceans over vast distances
                $base = $nearBase + ($nextBase - $nearBase) * ($shelfBlend * 0.5);

                // 4. Differentiated Plate Tectonics (using the narrow $boundary)
                $tectonic = 0.0;
                if ($boundary > 0.0) {
                    if ($near->continental && $next->continental) {
                        $tectonic = max(0.0, $convergence) * $boundary * self::UPLIFT_SCALE * 2.0;
                    } elseif (!$near->continental && !$next->continental) {
                        $tectonic = $convergence * ($boundary ** 1.5) * self::UPLIFT_SCALE * 1.2;
                    } else {
                        if ($convergence > 0) {
                            if ($near->continental) {
                                $tectonic = $convergence * $boundary * self::UPLIFT_SCALE * 1.5;
                            } else {
                                $tectonic = -$convergence * ($boundary ** 0.5) * self::UPLIFT_SCALE * 0.8;
                            }
                        } else {
                            $tectonic = $convergence * $boundary * self::UPLIFT_SCALE;
                        }
                    }
                }

                $rawElevation = $base + $tectonic + self::RELIEF_AMPLITUDE * $relief->fbmWrapped((float)$x, (float)$y, (float)$width);

                // 5. Bathymetric Flattening (The true "Continental Shelf" effect)
                // If we are just below sea level (0.0 to -1.0), flatten the depth curve.
                if ($rawElevation < 0.0 && $rawElevation > -1.0) {
                    $depth = abs($rawElevation); // Convert to positive 0.0 to 1.0
                    // A power of 2.5 means a depth of 0.5 gets compressed up to 0.17!
                    // This creates wide, shallow seas that eventually plunge.
                    $rawElevation = -($depth ** 2.5);
                }

                $elevation[$y][$x] = $rawElevation;
                $plateId[$y][$x] = $near->id;
                $minerals[$y][$x] = min(1.0, abs($tectonic));
            }
        }

        $elevation = HydraulicErosion::erode($elevation, $width, $height);

        return new Substrate($width, $height, $elevation, $plateId, $minerals, $plates);
    }

    private static function twoNearest(array $plates, float $x, float $y, int $width): array
    {
        $near = $plates[0];
        $nearDist = INF;
        $next = $plates[0];
        $nextDist = INF;

        foreach ($plates as $plate) {
            $dx = abs($plate->x - $x);
            // CYLINDRICAL WRAP: If distance is > half the map, go around the back!
            $dx = min($dx, $width - $dx);
            $dy = abs($plate->y - $y);

            $distance = hypot($dx, $dy) / $plate->weight;

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

    private static function convergence(Plate $a, Plate $b, int $width): float
    {
        $dx = $b->x - $a->x;
        // WRAP DELTA: If plates are wrapped, invert their spatial relationship
        if ($dx > $width / 2.0) {
            $dx -= $width;
        } elseif ($dx < -$width / 2.0) {
            $dx += $width;
        }

        $dy = $b->y - $a->y;
        $length = hypot($dx, $dy);
        if ($length <= 0.0) return 0.0;
        return (($a->driftX - $b->driftX) * $dx + ($a->driftY - $b->driftY) * $dy) / $length;
    }
}
