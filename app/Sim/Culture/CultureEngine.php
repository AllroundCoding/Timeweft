<?php

namespace App\Sim\Culture;

use App\Sim\Time\TharadiDate;
use App\Sim\World\Village;
use App\Sim\World\World;

/**
 * Culture drifts with material security (Inglehart/WVS, design doc 11): year by year a
 * settlement's values edge toward what its *current* conditions would generate — prosperity
 * loosens them toward self-expression, scarcity tightens them toward survival. This closes the
 * culture↔economy feedback: the boom-bust loop slowly reshapes the culture that shapes it.
 *
 * Deterministic (no RNG); the drift target reuses the same Cultural-Materialism mapping that
 * generated the culture in the first place, so generation and drift speak one language.
 */
final class CultureEngine
{
    private const DRIFT_RATE = 0.03;        // fraction of the way toward the target, per year

    private const SECURE_FOOD_DAYS = 10.0;  // food per head that counts as fully secure

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // values shift over years, so drift once at the turn of the year
        }

        $village = $world->village;
        $population = count($world->livingAgents());
        if ($population === 0) {
            return;
        }

        $target = Culture::fromMaterialConditions(
            $village->culture->name,
            1.0 - self::materialSecurity($village, $population),
            ($village->regionProfile ?? $world->region)->seasonalVolatility(),
        );

        $village->culture = $village->culture->driftedToward($target, self::DRIFT_RATE);
        $village->baselineCohesion = $village->culture->baselineCohesion();
    }

    /** How materially secure life currently feels: 0 (precarious) .. 1 (prosperous). */
    private static function materialSecurity(Village $village, int $population): float
    {
        $foodPerCapita = $village->stockpile->amount('food') / $population;
        $foodSecurity = max(0.0, min(1.0, $foodPerCapita / self::SECURE_FOOD_DAYS));
        $headroom = $village->carryingCapacity > 0
            ? max(0.0, min(1.0, 1.0 - $population / $village->carryingCapacity))
            : 1.0;

        return ($foodSecurity + $headroom) / 2.0;
    }
}
