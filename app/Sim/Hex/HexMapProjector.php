<?php

namespace App\Sim\Hex;

use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\Hydrology;
use App\Sim\Worldgen\Substrate;

/**
 * Projects the continuous worldgen onto a playable {@see HexGrid} (design doc 16; TWT-275). Each hex
 * samples the fields beneath it — biome and fertility ({@see Climate}), elevation and slope
 * ({@see Substrate}), rivers and lakes ({@see Hydrology}) — and derives a movement cost. The hexes are a
 * *play projection* of the one continuous world, not a second world: this is a pure, deterministic
 * function of (fields + resolution) with no RNG, so the same world at the same resolution always yields
 * the same grid.
 *
 * Movement cost here is a simple slope/water rule; the richer accessibility cost-surface (TWT-136) can
 * feed it once merged. The grid is addressed in axial coordinates with the play resolution mapped as a
 * uniform sampling lattice over the raster.
 */
final class HexMapProjector
{
    /** Open sea or lake — passable only at great cost on a land surface. */
    private const WATER_COST = 10.0;

    /** A river hex is the easiest going (the highway). */
    private const RIVER_COST = 0.5;

    /** How much the steepest land adds over flat plains (cost up to 1 + this). */
    private const SLOPE_WEIGHT = 4.0;

    public static function project(Substrate $substrate, Climate $climate, Hydrology $hydrology, int $cols, int $rows): HexGrid
    {
        $cols = max(1, $cols);
        $rows = max(1, $rows);
        $relief = max(1e-6, $substrate->highestElevation());

        $hexes = [];
        for ($r = 0; $r < $rows; $r++) {
            for ($q = 0; $q < $cols; $q++) {
                // The hex samples the world at its location — a uniform lattice spanning the raster.
                $x = $cols > 1 ? (int) round($q / ($cols - 1) * ($substrate->width - 1)) : intdiv($substrate->width, 2);
                $y = $rows > 1 ? (int) round($r / ($rows - 1) * ($substrate->height - 1)) : intdiv($substrate->height, 2);

                $isLand = $substrate->isLand($x, $y);
                $isLake = $hydrology->isLake($x, $y);
                $isRiver = $hydrology->isRiver($x, $y);

                $hexes[] = new Hex(
                    coord: new HexCoord($q, $r),
                    biome: $climate->biomeAt($x, $y),
                    elevation: $substrate->elevationAt($x, $y),
                    isLand: $isLand,
                    isRiver: $isRiver,
                    isLake: $isLake,
                    fertility: $climate->fertilityAt($x, $y),
                    movementCost: self::movementCost($isLand, $isLake, $isRiver, $substrate->slopeAt($x, $y), $relief),
                );
            }
        }

        return new HexGrid($cols, $rows, $hexes);
    }

    private static function movementCost(bool $isLand, bool $isLake, bool $isRiver, float $slope, float $relief): float
    {
        if (! $isLand || $isLake) {
            return self::WATER_COST;
        }
        if ($isRiver) {
            return self::RIVER_COST;
        }

        return 1.0 + self::SLOPE_WEIGHT * min(1.0, $slope / $relief);
    }
}
