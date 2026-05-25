<?php

namespace App\Sim\Worldgen;

final class HydraulicErosion
{
    private const EPSILON = 1.0e-4;
    private const FILL_SWEEPS = 60;
    private const INCISION = 0.006;
    private const INCISION_EXPONENT = 0.5;
    private const MAX_INCISION = 0.20;
    private const NEIGHBORS = [[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]];

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

    private static function fillDepressions(array $dem, int $width, int $height): array
    {
        $filled = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                // CHANGED: X-edges are no longer outlets. Only the poles (Y edges) and the sea.
                $outlet = $y === 0 || $y === $height - 1 || $dem[$y][$x] <= 0.0;
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
                        continue;
                    }
                    $lowestNeighbour = INF;
                    foreach (self::NEIGHBORS as [$dx, $dy]) {
                        $ny = $y + $dy;

                        // Cap Y (poles), Wrap X (longitude)
                        if ($ny < 0 || $ny >= $height) {
                            continue;
                        }
                        $nx = ($x + $dx + $width) % $width;

                        $lowestNeighbour = min($lowestNeighbour, $filled[$ny][$nx]);
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

    private static function flowAccumulation(array $surface, int $width, int $height): array
    {
        $flow = [];

        $elevations = [];
        $ys = [];
        $xs = [];
        $i = 0;

        for ($y = 0; $y < $height; $y++) {
            $flow[$y] = array_fill(0, $width, 1.0);
            for ($x = 0; $x < $width; $x++) {
                $elevations[$i] = $surface[$y][$x];
                $ys[$i] = $y;
                $xs[$i] = $x;
                $i++;
            }
        }

        // Massively faster than usort with a closure. Preserves perfect determinism.
        array_multisort(
            $elevations, SORT_DESC, SORT_NUMERIC,
            $ys, SORT_ASC, SORT_NUMERIC,
            $xs, SORT_ASC, SORT_NUMERIC
        );

        // Process cells top-down
        for ($k = 0; $k < $i; $k++) {
            $level = $elevations[$k];
            $y = $ys[$k];
            $x = $xs[$k];

            $lowest = $level;
            $targetX = -1;
            $targetY = -1;

            foreach (self::NEIGHBORS as [$dx, $dy]) {
                $ny = $y + $dy;

                // CHANGED: Cap Y, Wrap X
                if ($ny < 0 || $ny >= $height) {
                    continue;
                }
                $nx = ($x + $dx + $width) % $width;

                if ($surface[$ny][$nx] < $lowest) {
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
