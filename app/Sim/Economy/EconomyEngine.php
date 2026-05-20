<?php

namespace App\Sim\Economy;

use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;
use App\Sim\World\RegionProfile;
use App\Sim\World\World;

/**
 * The daily material loop (design doc 06): adults produce food and water into the
 * settlement granary, everyone consumes a ration, and an unmet ration drives up
 * hunger so scarcity becomes visible pressure. v1 keeps production comfortably
 * above consumption — dynamic carrying capacity computed from this comes next.
 *
 * Deterministic on purpose: no RNG, so adding it does not perturb the seeded run.
 */
final class EconomyEngine
{
    private const ADULT_AGE = 16;

    private const FOOD_PER_ADULT = 4.0;

    private const WATER_PER_ADULT = 4.0;

    /** Per-head daily ration; the divisor that turns land yield into a carrying capacity. */
    public const FOOD_PER_CAPITA = 1.0;

    private const WATER_PER_CAPITA = 1.0;

    /** Money an adult earns per day from their labor. */
    private const WAGE_PER_ADULT = 1.0;

    /**
     * Carrying capacity = the population the land's yield can feed, multiplied by
     * technology — so a small but high-tech settlement (think the Netherlands)
     * sustains far more than its acreage alone would. Labor then realizes this
     * ceiling in production: too few workers and the harvest falls short of it.
     */
    public static function carryingCapacityFor(float $landYield, float $technology = 1.0): int
    {
        return self::FOOD_PER_CAPITA > 0.0
            ? (int) floor($landYield * $technology / self::FOOD_PER_CAPITA)
            : 0;
    }

    /** The year-round average yield multiplier, weighted by how many months fall in each season. */
    public static function averageYieldMultiplier(RegionProfile $region): float
    {
        $sum = 0.0;
        foreach (TharadiCalendar::MONTHS as $month) {
            $sum += $region->yieldMultiplier($month['season']);
        }

        return $sum / count(TharadiCalendar::MONTHS);
    }

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        $village = $world->village;
        $region = $world->region;

        // Carrying capacity tracks the land's *average* annual yield — a lean desert
        // (mostly Sandstorm) sustains fewer souls than its peak-season yield suggests.
        $village->carryingCapacity = self::carryingCapacityFor(
            $village->landYield * self::averageYieldMultiplier($region),
            $village->technology,
        );

        $living = $world->livingAgents();
        $population = count($living);
        if ($population === 0) {
            return;
        }

        // Wages: each adult earns, saves a thrift-proportional share, and the rest
        // circulates into the communal treasury (which can later fund paid-to cooperation).
        $adults = 0;
        foreach ($living as $agent) {
            if ($agent->ageInYears($tick) < self::ADULT_AGE) {
                continue;
            }
            $adults++;
            $saved = self::WAGE_PER_ADULT * ((float) $agent->trait('thrift') / 100.0);
            $agent->stockpile->add('money', $saved);
            $world->village->stockpile->add('money', self::WAGE_PER_ADULT - $saved);
        }

        // Labor produces; technology multiplies it; the land × tech × season ceiling caps it.
        $tech = $village->technology;
        $ceiling = $village->landYield * $tech * $region->yieldMultiplier($date->season);
        $granary = $village->stockpile;
        $granary->add('food', min($adults * self::FOOD_PER_ADULT * $tech, $ceiling));
        $granary->add('water', min($adults * self::WATER_PER_ADULT * $tech, $ceiling));

        $foodShort = $population * self::FOOD_PER_CAPITA - $granary->withdraw('food', $population * self::FOOD_PER_CAPITA);
        $waterShort = $population * self::WATER_PER_CAPITA - $granary->withdraw('water', $population * self::WATER_PER_CAPITA);

        if ($foodShort > 0.0 || $waterShort > 0.0) {
            foreach ($living as $agent) {
                ($agent->needs['hunger'] ?? null)?->advance();
            }
        }
    }
}
