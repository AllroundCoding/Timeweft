<?php

namespace App\Sim\Worldgen;

/**
 * Fluvial erosion over the substrate DEM (design doc 13; TWT-262 pass 2). Two coupled steps that make
 * rivers shape the land:
 *
 *  1. **Fill the basins** — a Planchon-Darboux relaxation raises every closed depression to its spill
 *     level (with a hair of downhill gradient), so water always has a path to the sea. This dissolves the
 *     spurious little lakes that raw fractal relief leaves behind.
 *  2. **Incise** — route the rainfall downhill, accumulate it, and cut each cell down in proportion to the
 *     water passing through it (a stream-power law). Trunks carve deep valleys, headwaters barely a crease,
 *     so the drainage network is etched into the terrain and rivers sit in the valleys they cut.
 *
 * Pure, framework-free, and deterministic — a fixed function of the input heightmap, so the same world
 * reproduces the same valleys.
 */
final class HydraulicErosion
{
    private const EPSILON = 1.0e-4;       // the slight downhill slope enforced across a filled basin

    private const FILL_SWEEPS = 60;       // cap on relaxation passes (it breaks early once stable)

    private const INCISION = 0.006;       // stream-power coefficient — how hard flow cuts down

    private const INCISION_EXPONENT = 0.5; // sub-linear in flow, so trunk rivers don't runaway into chasms

    private const MAX_INCISION = 0.20;    // deepest a single channel may cut, relative to sea-level units

    /** 8-connected neighbour offsets, fixed order for deterministic tie-breaks. */
    private const NEIGHBORS = [[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]];

    /**
     * @param  list<list<float>>  $elevation  sea-level-relative heightmap
     * @return list<list<float>> the eroded heightmap — basins filled, valleys cut
     */
    public static function erode(array $elevation, int $width, int $height): array
    {
        $filled = self::fillDepressions($elevation, $width, $height);
        $flow = self::flowAccumulation($filled, $width, $height);

        $eroded = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $cut = min(self::MAX_INCISION, self::INCISION * ($flow[$y][$x] ** self::INCISION_EXPONENT));
                $row[] = $filled[$y][$x] - $cut;
            }
            $eroded[] = $row;
        }

        return $eroded;
    }

    /**
     * Planchon-Darboux depression filling: sea and map-edge cells are fixed outlets; every other cell is
     * pulled down to the lowest level that still drains to an outlet (plus a tiny gradient). Alternating
     * scan directions converge in a handful of sweeps on relief terrain.
     *
     * @param  list<list<float>>  $dem
     * @return list<list<float>>
     */
    private static function fillDepressions(array $dem, int $width, int $height): array
    {
        $filled = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $outlet = $x === 0 || $y === 0 || $x === $width - 1 || $y === $height - 1 || $dem[$y][$x] <= 0.0;
                $row[] = $outlet ? $dem[$y][$x] : INF;
            }
            $filled[] = $row;
        }

        for ($sweep = 0; $sweep < self::FILL_SWEEPS; $sweep++) {
            $changed = false;
            $forward = $sweep % 2 === 0;
            for ($i = 0; $i < $height; $i++) {
                $y = $forward ? $i : $height - 1 - $i;
                for ($j = 0; $j < $width; $j++) {
                    $x = $forward ? $j : $width - 1 - $j;
                    if ($filled[$y][$x] <= $dem[$y][$x]) {
                        continue; // already at ground level — an outlet or resolved
                    }
                    $lowestNeighbour = INF;
                    foreach (self::NEIGHBORS as [$dx, $dy]) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;
                        if ($nx >= 0 && $ny >= 0 && $nx < $width && $ny < $height) {
                            $lowestNeighbour = min($lowestNeighbour, $filled[$ny][$nx]);
                        }
                    }
                    $spill = $lowestNeighbour + self::EPSILON;
                    if ($dem[$y][$x] >= $spill) {
                        $filled[$y][$x] = $dem[$y][$x];
                        $changed = true;
                    } elseif ($filled[$y][$x] > $spill) {
                        $filled[$y][$x] = $spill;
                        $changed = true;
                    }
                }
            }
            if (! $changed) {
                break;
            }
        }

        return $filled;
    }

    /**
     * Uniform-rainfall flow accumulation over a depression-free surface: each cell sheds to its lowest
     * neighbour, and visiting from the top down sums the water passing through every cell.
     *
     * @param  list<list<float>>  $surface
     * @return list<list<float>>
     */
    private static function flowAccumulation(array $surface, int $width, int $height): array
    {
        $flow = [];
        $cells = [];
        for ($y = 0; $y < $height; $y++) {
            $flow[$y] = array_fill(0, $width, 1.0); // every cell catches one unit of rain
            for ($x = 0; $x < $width; $x++) {
                $cells[] = [$surface[$y][$x], $x, $y];
            }
        }

        usort($cells, static fn (array $a, array $b): int => [$b[0], $a[2], $a[1]] <=> [$a[0], $b[2], $b[1]]);

        foreach ($cells as [$level, $x, $y]) {
            $lowest = $level;
            $targetX = -1;
            $targetY = -1;
            foreach (self::NEIGHBORS as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx >= 0 && $ny >= 0 && $nx < $width && $ny < $height && $surface[$ny][$nx] < $lowest) {
                    $lowest = $surface[$ny][$nx];
                    $targetX = $nx;
                    $targetY = $ny;
                }
            }
            if ($targetX >= 0) {
                $flow[$targetY][$targetX] += $flow[$y][$x];
            }
        }

        return $flow;
    }
}
