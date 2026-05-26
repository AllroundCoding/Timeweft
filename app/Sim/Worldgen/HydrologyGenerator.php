<?php

namespace App\Sim\Worldgen;

/**
 * Routes rainfall over the terrain to derive the {@see Hydrology} (design doc 13; TWT-131).
 *
 * Each land cell sheds its water to the neighbour that is most "downhill" — lower ground first, and on
 * flat ground (the substrate's plate interiors are dead level) the neighbour nearer the sea, found by a
 * breadth-first distance-to-coast field. Visiting cells from the highest down, every cell hands its
 * gathered water to that neighbour, so flow accumulates along valleys out to the sea: cells past a
 * channel threshold are rivers, and a true basin floor with nowhere lower to drain gathers into a lake.
 *
 * Deterministic and framework-free: a pure function of the substrate + climate (ties broken by the
 * coast-distance field, then by position). A first pass — no depression-filling/overflow, so an enclosed
 * basin terminates in a lake rather than spilling onward.
 */
final class HydrologyGenerator
{
    /** Accumulated flow (in rainfall units) a cell must gather to read as a river. Lower for a denser, branchier network; raise to show only major rivers. */
    private const RIVER_THRESHOLD = 50.0; // 30.0

    /** Inflow a basin floor must gather to read as a standing lake (filters out shallow noise pits). Raise for fewer, only-larger lakes. */
    private const LAKE_THRESHOLD = 20.0; // 18.0

    /** Float tolerance for treating two cells as the same height when routing across flats. Rarely needs tuning. */
    private const FLAT_EPSILON = 1.0e-9;

    /** 8-connected neighbour offsets, in a fixed order so ties resolve deterministically. */
    private const NEIGHBORS = [[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]];

    public static function generate(Substrate $substrate, Climate $climate): Hydrology
    {
        $width = $substrate->width;
        $height = $substrate->height;

        $distance = self::distanceToSea($substrate);

        // Each land cell starts with its own rainfall; sea contributes nothing to the channels.
        $accumulation = [];
        $cells = [];

        // Parallel arrays for high-speed multisort
        $elevations = [];
        $distances = [];
        $ys = [];
        $xs = [];

        $i = 0;
        for ($y = 0; $y < $height; $y++) {
            $accumulation[$y] = [];
            for ($x = 0; $x < $width; $x++) {
                $land = $substrate->isLand($x, $y);
                $accumulation[$y][$x] = $land ? $climate->precipitationAt($x, $y) : 0.0;

                if ($land) {
                    $cells[$i] = [$x, $y];
                    $elevations[$i] = $substrate->elevationAt($x, $y);
                    $distances[$i] = $distance[$y][$x];
                    $ys[$i] = $y;
                    $xs[$i] = $x;
                    $i++;
                }
            }
        }

        // PERFORMANCE MASSIVE BOOST: array_multisort runs in native C.
        // Sort Highest first. Ties broken by distance (furthest from sea flows first).
        array_multisort(
            $elevations, SORT_DESC, SORT_NUMERIC,
            $distances, SORT_DESC, SORT_NUMERIC,
            $ys, SORT_DESC, SORT_NUMERIC,
            $xs, SORT_DESC, SORT_NUMERIC,
            $cells
        );

        $sink = [];
        $targetMap = []; // We use this to figure out where rivers touch the sea
        for ($y = 0; $y < $height; $y++) {
            $sink[$y] = array_fill(0, $width, false);
            $targetMap[$y] = array_fill(0, $width, null);
        }

        foreach ($cells as [$x, $y]) {
            $elevation = $substrate->elevationAt($x, $y);
            $coast = $distance[$y][$x];
            $targetX = -1;
            $targetY = -1;

            foreach (self::NEIGHBORS as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($ny < 0 || $ny >= $height) {
                    continue; // Poles don't wrap
                }
                $nx = ($nx + $width) % $width;
                $neighbourElevation = $substrate->elevationAt($nx, $ny);
                // Downhill if lower ground, or — on the flat — nearer the coast than where we've settled.
                if ($neighbourElevation < $elevation - self::FLAT_EPSILON
                    || (abs($neighbourElevation - $elevation) <= self::FLAT_EPSILON && $distance[$ny][$nx] < $coast)) {
                    $elevation = $neighbourElevation;
                    $coast = $distance[$ny][$nx];
                    $targetX = $nx;
                    $targetY = $ny;
                }
            }

            if ($targetX >= 0) {
                $accumulation[$targetY][$targetX] += $accumulation[$y][$x];
                $targetMap[$y][$x] = [$targetX, $targetY]; // Save where the water went
            } else {
                $sink[$y][$x] = true; // a basin floor with nowhere lower or seaward to go
            }
        }

        $flow = [];
        $river = [];
        $lake = [];
        $delta = [];

        // Initialize delta array
        for ($y = 0; $y < $height; $y++) {
            $delta[$y] = array_fill(0, $width, false);
        }

        for ($y = 0; $y < $height; $y++) {
            $flowRow = [];
            $riverRow = [];
            $lakeRow = [];
            for ($x = 0; $x < $width; $x++) {
                $gathered = $accumulation[$y][$x];
                $isRiver = $substrate->isLand($x, $y) && $gathered > self::RIVER_THRESHOLD;

                $flowRow[] = $gathered;
                $riverRow[] = $isRiver;
                $lakeRow[] = $sink[$y][$x] && $gathered > self::LAKE_THRESHOLD;

                // DELTA DETECTION: If a river flows into a non-land cell, it's a delta!
                if ($isRiver && $targetMap[$y][$x] !== null) {
                    [$tx, $ty] = $targetMap[$y][$x];
                    if (! $substrate->isLand($tx, $ty)) {
                        $delta[$y][$x] = true; // The coastal land cell
                        $delta[$ty][$tx] = true; // The shallow sea cell
                    }
                }
            }
            $flow[] = $flowRow;
            $river[] = $riverRow;
            $lake[] = $lakeRow;
        }

        return new Hydrology($width, $height, $flow, $river, $lake, $delta);
    }

    /**
     * Breadth-first graph distance from every cell to the nearest sea (8-connected, elevation-agnostic).
     * On dead-flat ground this is what gives water a seaward direction; sea cells are 0.
     *
     * @return list<list<int>>
     */
    private static function distanceToSea(Substrate $substrate): array
    {
        $width = $substrate->width;
        $height = $substrate->height;

        $distance = [];
        $queue = [];
        for ($y = 0; $y < $height; $y++) {
            $distance[$y] = array_fill(0, $width, PHP_INT_MAX);
            for ($x = 0; $x < $width; $x++) {
                if (! $substrate->isLand($x, $y)) {
                    $distance[$y][$x] = 0;
                    $queue[] = [$x, $y];
                }
            }
        }

        for ($head = 0; $head < count($queue); $head++) {
            [$x, $y] = $queue[$head];
            $next = $distance[$y][$x] + 1;
            foreach (self::NEIGHBORS as [$dx, $dy]) {
                $ny = $y + $dy;
                if ($ny < 0 || $ny >= $height) {
                    continue; // latitude is capped at the poles
                }
                $nx = ($x + $dx + $width) % $width; // longitude wraps around the globe
                if ($next < $distance[$ny][$nx]) {
                    $distance[$ny][$nx] = $next;
                    $queue[] = [$nx, $ny];
                }
            }
        }

        return $distance;
    }
}
