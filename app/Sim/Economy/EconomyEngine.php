<?php

namespace App\Sim\Economy;

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

    /** Below one day of food per head the granary is acutely empty — famine. */
    private const SECURE_FOOD_DAYS = 1.0;

    /** How sharply mortality rises as the granary empties (the die-back of boom-bust). */
    private const STARVATION_SEVERITY = 5.0;

    /** Days of food per head the granary can hold; beyond this, surplus spoils. */
    private const STORAGE_DAYS = 30.0;

    /** Per-adult daily yield of each basket good (before tech × season); the real diet behind the calories. */
    private const BASKET_YIELD = ['grain' => 3.0, 'dates' => 1.5, 'goat meat' => 1.5];

    /** Fraction of a good's perishability that spoils per day (meat rots, grain keeps). */
    private const SPOIL_RATE = 0.4;

    /** Nutrition of eating raw, uncooked scraps when no meal can be cooked. */
    private const RAW_SCRAP_NUTRITION = 25.0;

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

    /** The year-round average yield multiplier (the region owns the season weighting). */
    public static function averageYieldMultiplier(RegionProfile $region): float
    {
        return $region->averageYield();
    }

    /**
     * Produce the day's basket of foodstuffs from the settlement's labor, spoil the perishables
     * (meat rots, grain keeps), and cap the stores. The real diet behind the abstract food calories.
     */
    private static function produceBasket(World $world, int $adults, int $population, float $tech, float $seasonMult): void
    {
        $granary = $world->village->stockpile;
        $cap = self::STORAGE_DAYS * $population;

        foreach (self::BASKET_YIELD as $name => $perAdult) {
            $granary->add($name, $adults * $perAdult * $tech * $seasonMult);
            $good = $world->goods->get($name);
            if ($good !== null) {
                $granary->withdraw($name, $granary->amount($name) * ($good->perishability / 100.0) * self::SPOIL_RATE);
            }
            $granary->withdraw($name, max(0.0, $granary->amount($name) - $cap));
        }
    }

    /**
     * Cook the day's meals from the stores — richest recipe first — feed the people, and rate the
     * diet 0..1 against the best meal possible. What can't be cooked is eaten raw (poor nutrition),
     * so a lean season where the meat has spoiled drops the diet from hearty stew to bare grain.
     */
    public static function cookedDietQuality(World $world, int $population): float
    {
        if ($population <= 0) {
            return 1.0;
        }

        $granary = $world->village->stockpile;
        $goods = $world->goods;
        $recipes = $world->recipes->all();
        usort($recipes, fn (Recipe $a, Recipe $b): int => $b->meal($goods)->nutrition <=> $a->meal($goods)->nutrition);
        $best = $recipes !== [] ? $recipes[0]->meal($goods)->nutrition : 1.0;

        $fed = 0;
        $nutrition = 0.0;
        foreach ($recipes as $recipe) {
            while ($fed < $population && ($meal = $recipe->cook($granary, $goods)) !== null) {
                $fed++;
                $nutrition += $meal->nutrition;
            }
            if ($fed >= $population) {
                break;
            }
        }
        $nutrition += ($population - $fed) * self::RAW_SCRAP_NUTRITION;

        return $best > 0.0 ? min(1.0, $nutrition / $population / $best) : 1.0;
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

        // Wages: each adult earns, saves a thrift-proportional share, and the rest circulates into
        // the communal treasury. Thrift is the agent's trait as the faith shapes it — an ascetic,
        // sanctity-weighted faith makes the devout save more (and the nominal believer barely).
        $faith = $village->faith();
        $adults = 0;
        foreach ($living as $agent) {
            if ($agent->ageInYears($tick) < self::ADULT_AGE) {
                continue;
            }
            $adults++;
            $thrift = $faith->shape($agent, 'thrift', (float) $agent->trait('thrift'));
            $saved = self::WAGE_PER_ADULT * ($thrift / 100.0);
            $agent->stockpile->add('money', $saved);
            $village->stockpile->add('money', self::WAGE_PER_ADULT - $saved);
        }

        // Labor produces; technology multiplies it; the land × tech × season ceiling caps it.
        $tech = $village->technology;
        $ceiling = $village->landYield * $tech * $region->yieldMultiplier($date->season);
        $granary = $village->stockpile;
        $granary->add('food', min($adults * self::FOOD_PER_ADULT * $tech, $ceiling));
        $granary->add('water', min($adults * self::WATER_PER_ADULT * $tech, $ceiling));

        // Stores are finite: a granary can only hold so much before the surplus spoils.
        $storageCap = self::STORAGE_DAYS * $population;
        $granary->withdraw('food', max(0.0, $granary->amount('food') - $storageCap));
        $granary->withdraw('water', max(0.0, $granary->amount('water') - $storageCap));

        // The diet: real foodstuffs produced and spoiled, then cooked into the meals people eat.
        if (isset($world->goods, $world->recipes)) {
            self::produceBasket($world, $adults, $population, $tech, $region->yieldMultiplier($date->season));
            $village->dietQuality = self::cookedDietQuality($world, $population);
        }

        $foodShort = $population * self::FOOD_PER_CAPITA - $granary->withdraw('food', $population * self::FOOD_PER_CAPITA);
        $waterShort = $population * self::WATER_PER_CAPITA - $granary->withdraw('water', $population * self::WATER_PER_CAPITA);

        if ($foodShort > 0.0 || $waterShort > 0.0) {
            foreach ($living as $agent) {
                ($agent->needs['hunger'] ?? null)?->advance();
            }
        }

        self::updateFoodSecurity($world, $tick, $date, $granary->amount('food') / $population);
    }

    /** A near-empty granary raises the starvation mortality factor and chronicles the famine. */
    private static function updateFoodSecurity(World $world, int $tick, TharadiDate $date, float $foodPerCapita): void
    {
        $village = $world->village;

        $village->starvationFactor = $foodPerCapita >= self::SECURE_FOOD_DAYS
            ? 1.0
            : 1.0 + (1.0 - $foodPerCapita / self::SECURE_FOOD_DAYS) * self::STARVATION_SEVERITY;

        if ($village->starvationFactor > 1.0 && ! $village->inFamine) {
            $village->inFamine = true;
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — famine grips %s as the granary runs dry.',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name,
            ));
        } elseif ($village->starvationFactor <= 1.0 && $village->inFamine) {
            $village->inFamine = false;
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — the famine at %s breaks; the granary fills again.',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name,
            ));
        }
    }
}
