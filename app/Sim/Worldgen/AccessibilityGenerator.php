<?php

namespace App\Sim\Worldgen;

/**
 * Builds the {@see AccessibilityField} from the terrain (design doc 13; TWT-136). Friction per cell:
 *
 *  - **rivers are highways** — a navigable channel is the cheapest way to move (boats, towpaths);
 *  - **plains are the baseline**, growing more costly with **slope** (ruggedness slows a march), scaled
 *    against the world's own relief so the penalty is sensible at any elevation scale;
 *  - **lakes and open sea are barriers** — passable at a steep cost, so least-cost land routes hug the
 *    coast and ford at narrows rather than strike across water (true sea-lanes await the ocean fields).
 *
 * Pure: a deterministic function of substrate + hydrology, no RNG.
 */
final class AccessibilityGenerator
{
    /** A river channel — the cheapest movement on the map. */
    public const RIVER_FRICTION = 0.5;

    /** Standing inland water — a barrier to cross. */
    public const LAKE_FRICTION = 6.0;

    /** Open sea — passable only at great cost on a land surface. */
    public const SEA_FRICTION = 10.0;

    /** How much the steepest land adds over flat plains (friction up to 1 + this). */
    public const SLOPE_WEIGHT = 4.0;

    public static function generate(Substrate $substrate, Hydrology $hydrology): AccessibilityField
    {
        $relief = max(1e-6, $substrate->highestElevation());

        $friction = [];
        for ($y = 0; $y < $substrate->height; $y++) {
            $row = [];
            for ($x = 0; $x < $substrate->width; $x++) {
                $row[] = self::frictionAt($substrate, $hydrology, $x, $y, $relief);
            }
            $friction[] = $row;
        }

        return new AccessibilityField($substrate->width, $substrate->height, $friction);
    }

    private static function frictionAt(Substrate $substrate, Hydrology $hydrology, int $x, int $y, float $relief): float
    {
        if (! $substrate->isLand($x, $y)) {
            return self::SEA_FRICTION;
        }
        if ($hydrology->isLake($x, $y)) {
            return self::LAKE_FRICTION;
        }
        if ($hydrology->isRiver($x, $y)) {
            return self::RIVER_FRICTION;
        }

        return 1.0 + self::SLOPE_WEIGHT * min(1.0, $substrate->slopeAt($x, $y) / $relief);
    }
}
