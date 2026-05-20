<?php

namespace App\Sim\Economy;

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

    /** Carrying capacity = the population the land's food yield can sustainably feed. */
    public static function carryingCapacityFor(float $landYield): int
    {
        return self::FOOD_PER_CAPITA > 0.0 ? (int) floor($landYield / self::FOOD_PER_CAPITA) : 0;
    }

    public static function runDay(World $world, int $tick): void
    {
        $living = $world->livingAgents();
        $population = count($living);
        if ($population === 0) {
            return;
        }

        $adults = 0;
        foreach ($living as $agent) {
            if ($agent->ageInYears($tick) >= self::ADULT_AGE) {
                $adults++;
            }
        }

        // Labor produces, but the land caps the harvest — the Malthusian ceiling.
        $yield = $world->village->landYield;
        $granary = $world->village->stockpile;
        $granary->add('food', min($adults * self::FOOD_PER_ADULT, $yield));
        $granary->add('water', min($adults * self::WATER_PER_ADULT, $yield));

        $foodShort = $population * self::FOOD_PER_CAPITA - $granary->withdraw('food', $population * self::FOOD_PER_CAPITA);
        $waterShort = $population * self::WATER_PER_CAPITA - $granary->withdraw('water', $population * self::WATER_PER_CAPITA);

        if ($foodShort > 0.0 || $waterShort > 0.0) {
            foreach ($living as $agent) {
                ($agent->needs['hunger'] ?? null)?->advance();
            }
        }
    }
}
