<?php

namespace App\Sim\Economy;

use App\Sim\Time\TharadiDate;
use App\Sim\World\Agent;
use App\Sim\World\RegionProfile;
use App\Sim\World\Village;
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

    /** A day's cost of keep at the reference food holding, as a share of the daily wage (TWT-135). */
    private const COST_OF_LIVING = 0.1;

    /** Below one day of food per head the granary is acutely empty — famine. */
    private const SECURE_FOOD_DAYS = 1.0;

    /** How sharply mortality rises as the granary empties (the die-back of boom-bust). */
    private const STARVATION_SEVERITY = 5.0;

    /** Days of food per head a basic, low-tech granary holds — the pre-preservation struggle baseline. */
    private const BASE_STORAGE_DAYS = 30.0;

    /** Days of storage each step of preservation technology (granaries, salting, cold storage) adds. */
    private const PRESERVATION_DAYS_PER_TECH = 30.0;

    /** Boserup intensification: how fast sustained pressure × surplus × openness ratchets technology up. */
    private const INNOVATION_RATE = 0.08;

    /** Days of stored food per head that count as enough surplus to spare for innovation. */
    private const INNOVATION_SURPLUS_DAYS = 5.0;

    /** How fast overshoot (population past K) exhausts the land each year, per unit of overshoot. */
    private const DEGRADE_RATE = 0.15;

    /** How fast land left fallow (worked below K) heals back toward its pristine yield each year. */
    private const RECOVERY_RATE = 0.05;

    /** The most the land can be exhausted to — a fraction of its pristine yield. */
    private const LAND_FLOOR = 0.3;

    /** How wide ordinary good/bad harvest years swing production, scaled by the region's volatility. */
    private const HARVEST_SWING = 0.5;

    /** The leanest an ordinary (non-catastrophic) harvest can fall to. */
    private const HARVEST_FLOOR = 0.2;

    /** How much technology must climb since the last record to chronicle a notable advance. */
    private const TECH_ADVANCE_STEP = 0.1;

    /** Land below this fraction of its base counts as exhausted (chronicle onset). */
    private const LAND_EXHAUSTED = 0.9;

    /** A harvest at/below this is a notably lean year; at/above the bumper bound, a notably good one. */
    private const LEAN_HARVEST = 0.85;

    private const BUMPER_HARVEST = 1.15;

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
     * Days of food per head the granary can hold before the surplus spoils — and the buffer a
     * settlement banks to ride out the lean season. It grows with preservation technology (granaries,
     * salting, cold storage came late in our own history): a low-tech settlement struggles to keep
     * food through a long Sandstorm, while an advanced one banks the brief harvest and smooths it.
     */
    public static function storageDays(float $technology): float
    {
        return self::BASE_STORAGE_DAYS + max(0.0, $technology - 1.0) * self::PRESERVATION_DAYS_PER_TECH;
    }

    /** The region whose conditions this settlement lives under — its own, or the world's as a fallback. */
    public static function regionOf(World $world): RegionProfile
    {
        return $world->village->regionProfile ?? $world->region;
    }

    /**
     * Produce the day's basket of foodstuffs from the settlement's labor, spoil the perishables
     * (meat rots, grain keeps), and cap the stores. The real diet behind the abstract food calories.
     * The basket is the *region's* — a desert grows grain, dates, and herd meat; a fertile sownland
     * grows grain, fruit, and herbs — so settlements of different biomes accumulate distinct stores.
     */
    private static function produceBasket(World $world, int $adults, int $population, float $tech, float $seasonMult): void
    {
        $granary = $world->village->stockpile;
        $cap = self::storageDays($tech) * $population;

        foreach (self::regionOf($world)->basket() as $name => $perAdult) {
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

        // Only the meals this region can source from its own basket — so a neighbour's recipes
        // (a sownland's fruit loaf beside a desert) never raise this settlement's "best possible meal".
        $basket = self::regionOf($world)->basket();
        $recipes = array_values(array_filter(
            $world->recipes->all(),
            static fn (Recipe $r): bool => array_diff(array_keys($r->ingredients), array_keys($basket)) === [],
        ));
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

    /**
     * The settlement's mutual aid 0..1 — its average (faith-shaped) generosity. A generous,
     * collectivist village shares the shortfall in a famine and loses fewer souls; a stingy one
     * hoards and the vulnerable die. Sahlins' reciprocity (doc 11).
     */
    public static function mutualAid(World $world, int $tick): float
    {
        $faith = $world->village->faith();
        $sum = 0.0;
        $adults = 0;
        foreach ($world->livingAgents() as $agent) {
            if ($agent->ageInYears($tick) < self::ADULT_AGE) {
                continue;
            }
            $adults++;
            $sum += $faith->shape($agent, 'generosity', (float) ($agent->trait('generosity') ?? 50.0));
        }

        return $adults > 0 ? ($sum / $adults) / 100.0 : 0.5;
    }

    /**
     * Boserup's intensification ratchet (design doc 12): the spur to innovate is weak far below
     * carrying capacity and strong as population presses against (or past) it — but only a settlement
     * with surplus to spare and the cultural openness to try will act on it. Technology, once won,
     * sticks (monotonic). Run once a year; deterministic (no RNG).
     */
    public static function advanceTechnology(World $world, int $tick, ?TharadiDate $date = null): void
    {
        $village = $world->village;
        $population = count($world->livingAgents());
        if ($population === 0) {
            return;
        }

        $pressure = $village->carryingCapacity > 0 ? $population / $village->carryingCapacity : 0.0;
        $surplus = self::innovationSurplus($village, $population);
        $openness = self::settlementOpenness($world, $tick);

        $village->technology += self::technologyGrowth($pressure, $surplus, $openness);

        // Chronicle the *rise*: a notable climb in technology, so growth is legible alongside the fall.
        if ($date !== null && $village->technology - $village->lastTechMilestone >= self::TECH_ADVANCE_STEP) {
            $village->lastTechMilestone = $village->technology;
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s masters new techniques; its craft and yield advance (technology %.2f).',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name, $village->technology,
            ), 'tech-advance', [], [], ['intensification']);
        }
    }

    /** The yearly technology gain from Boserupian pressure × surplus × openness; never negative (knowledge sticks). */
    public static function technologyGrowth(float $pressure, float $surplus, float $openness): float
    {
        $spur = max(0.0, $pressure) ** 2; // little incentive far below K, a sharp one near or past it
        $surplus = max(0.0, min(1.0, $surplus));
        $openness = max(0.0, min(1.0, $openness));

        return self::INNOVATION_RATE * $spur * $surplus * $openness;
    }

    /** Days of stored food per head, normalized 0..1 — the slack a settlement can spare to intensify. */
    private static function innovationSurplus(Village $village, int $population): float
    {
        $foodPerCapita = $village->stockpile->amount('food') / $population;

        return max(0.0, min(1.0, $foodPerCapita / self::INNOVATION_SURPLUS_DAYS));
    }

    /** The settlement's average openness 0..1 — its propensity to try the new (consumes the inert openness trait). */
    private static function settlementOpenness(World $world, int $tick): float
    {
        $sum = 0.0;
        $adults = 0;
        foreach ($world->livingAgents() as $agent) {
            if ($agent->ageInYears($tick) < self::ADULT_AGE) {
                continue;
            }
            $adults++;
            $sum += (float) ($agent->trait('openness') ?? 50.0);
        }

        return $adults > 0 ? ($sum / $adults) / 100.0 : 0.5;
    }

    /**
     * Land degradation (Diamond / Tainter overshoot): pushing population past K over-works the land
     * and its yield erodes; working below K lets it lie fallow and heal back toward its pristine base.
     * This is the *organic* fall — overshoot scars the land, K drops, the die-back deepens, and only
     * once pressure eases does the land (and the ceiling) recover. Run once a year; deterministic.
     */
    public static function degradeLand(World $world, ?int $tick = null, ?TharadiDate $date = null): void
    {
        $village = $world->village;
        $population = count($world->livingAgents());
        if ($population === 0) {
            return;
        }

        $pressure = $village->carryingCapacity > 0 ? $population / $village->carryingCapacity : 0.0;
        $floor = self::LAND_FLOOR * $village->baseLandYield;

        $village->landYield = max($floor, min(
            $village->baseLandYield,
            $village->landYield + self::landYieldChange($village->landYield, $village->baseLandYield, $pressure),
        ));

        if ($date === null || $tick === null) {
            return; // unit-test path: degrade the value without chronicling
        }

        // Chronicle the turning points: when overuse exhausts the land, and when fallow heals it.
        $exhausted = $village->landYield < self::LAND_EXHAUSTED * $village->baseLandYield;
        if ($exhausted && ! $village->landExhausted) {
            $village->landExhausted = true;
            $event = $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — the land around %s is exhausted from overuse; it yields less than it once did.',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name,
            ), 'land-exhausted', [], [], ['overuse']);
            $village->landExhaustedEventId = $event->id;
        } elseif (! $exhausted && $village->landExhausted) {
            $village->landExhausted = false;
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — left fallow, the land around %s recovers its old vigour.',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name,
            ), 'land-recovered', [], $village->landExhaustedEventId !== null ? [$village->landExhaustedEventId] : []);
            $village->landExhaustedEventId = null;
        }
    }

    /** Signed yearly change in land yield: negative when overshoot exhausts the land, positive as fallow heals it. */
    public static function landYieldChange(float $landYield, float $baseLandYield, float $pressure): float
    {
        if ($pressure > 1.0) {
            return -self::DEGRADE_RATE * ($pressure - 1.0) * $baseLandYield;
        }

        return self::RECOVERY_RATE * ($baseLandYield - $landYield);
    }

    /**
     * Roll this year's harvest (design doc 06): ordinary years swing good or lean around the average,
     * the spread set by the region's volatility — the everyday risk granaries and mutual aid exist to
     * buffer, distinct from the rare catastrophic shock. Drawn from an independent sub-stream so the
     * added randomness never perturbs the seeded births and deaths. Deterministic for a given seed.
     */
    public static function rollHarvest(World $world, int $tick, TharadiDate $date): void
    {
        $roll = $world->rng->fork('harvest/'.$date->year)->float(-1.0, 1.0);
        $village = $world->village;
        $village->harvestQuality = self::harvestQuality($roll, self::regionOf($world)->seasonalVolatility());
        $village->leanHarvestEventId = null;

        // Chronicle the standout years — a lean one becomes a citable cause if a famine follows.
        if ($village->harvestQuality <= self::LEAN_HARVEST) {
            $event = $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — a lean harvest at %s; the granary fills slowly (yield %d%%).',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name, (int) round($village->harvestQuality * 100),
            ), 'harvest-lean', [], [], ['lean-harvest']);
            $village->leanHarvestEventId = $event->id;
        } elseif ($village->harvestQuality >= self::BUMPER_HARVEST) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — a bountiful harvest at %s fills the stores (yield %d%%).',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name, (int) round($village->harvestQuality * 100),
            ), 'harvest-bumper', [], [], ['bumper-harvest']);
        }
    }

    /** Map a roll in [-1,1] and the region's volatility to a harvest multiplier around 1.0 (floored). */
    public static function harvestQuality(float $roll, float $volatility): float
    {
        return max(self::HARVEST_FLOOR, 1.0 + $roll * $volatility * self::HARVEST_SWING);
    }

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        $village = $world->village;
        $region = self::regionOf($world);

        // Once a year: tech ratchets the ceiling up (Boserup), overuse erodes the land beneath it
        // (overshoot), and the harvest is rolled good or lean for the year ahead.
        if ($date->monthIndex === 0 && $date->dayOfMonth === 1) {
            self::advanceTechnology($world, $tick, $date);
            self::degradeLand($world, $tick, $date);
            self::rollHarvest($world, $tick, $date);
        }

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

        // Labor produces; technology multiplies it; the land × tech × season ceiling caps it; then the
        // year's harvest scales the take — a bumper year overflows the average, a lean year falls short.
        $tech = $village->technology;
        $harvest = $village->harvestQuality;
        $ceiling = $village->landYield * $tech * $region->yieldMultiplier($date->season);
        $granary = $village->stockpile;
        $granary->add('food', min($adults * self::FOOD_PER_ADULT * $tech, $ceiling) * $harvest);
        $granary->add('water', min($adults * self::WATER_PER_ADULT * $tech, $ceiling) * $harvest);

        // Stores are finite: a granary can only hold so much before the surplus spoils.
        $storageCap = self::storageDays($tech) * $population;
        $granary->withdraw('food', max(0.0, $granary->amount('food') - $storageCap));
        $granary->withdraw('water', max(0.0, $granary->amount('water') - $storageCap));

        // The diet: real foodstuffs produced and spoiled, then cooked into the meals people eat.
        if (isset($world->goods, $world->recipes)) {
            self::produceBasket($world, $adults, $population, $tech, $region->yieldMultiplier($date->season) * $harvest);
            $village->dietQuality = self::cookedDietQuality($world, $population);
        }

        $foodShort = $population * self::FOOD_PER_CAPITA - $granary->withdraw('food', $population * self::FOOD_PER_CAPITA);
        $waterShort = $population * self::WATER_PER_CAPITA - $granary->withdraw('water', $population * self::WATER_PER_CAPITA);

        if ($foodShort > 0.0 || $waterShort > 0.0) {
            foreach ($living as $agent) {
                ($agent->needs['hunger'] ?? null)?->advance();
            }
        }

        // Cost of living (TWT-135): each adult spends on its keep at the settlement's local food price,
        // drawn from personal savings — so scarcity eats into wealth and plenty lets the thrifty build it.
        // Closes pricing's spender/saver half (TWT-47/23). The treasury and the free ration are untouched,
        // so the canonical narrative is unmoved; only personal purses feel the price.
        self::chargeCostOfLiving($living, $tick, $granary->amount('food'), $population);

        $village->mutualAid = self::mutualAid($world, $tick);
        self::updateFoodSecurity($world, $tick, $date, $granary->amount('food') / $population);
    }

    /** A near-empty granary raises the starvation mortality factor and chronicles the famine. */
    /** The day's cost of keep for one adult, dearer where food is scarce — its local price (TWT-47/135). */
    public static function costOfLiving(float $foodStock, int $population): float
    {
        return self::COST_OF_LIVING * Pricing::localPrice(1.0, $foodStock, $population);
    }

    /**
     * Charge each adult its keep, drawn from personal savings and never below empty — so a dear, scarce
     * settlement eats into personal wealth while a cheap, abundant one leaves more for thrift to keep.
     *
     * @param  list<Agent>  $living
     */
    private static function chargeCostOfLiving(array $living, int $tick, float $foodStock, int $population): void
    {
        $cost = self::costOfLiving($foodStock, $population);
        foreach ($living as $agent) {
            if ($agent->ageInYears($tick) < self::ADULT_AGE) {
                continue;
            }
            $agent->stockpile->withdraw('money', min($agent->stockpile->amount('money'), $cost));
        }
    }

    private static function updateFoodSecurity(World $world, int $tick, TharadiDate $date, float $foodPerCapita): void
    {
        $village = $world->village;

        $village->starvationFactor = $foodPerCapita >= self::SECURE_FOOD_DAYS
            ? 1.0
            : 1.0 + (1.0 - $foodPerCapita / self::SECURE_FOOD_DAYS) * self::STARVATION_SEVERITY;

        if ($village->starvationFactor > 1.0 && ! $village->inFamine) {
            $village->inFamine = true;
            // Cite the material drivers behind the shortfall: exhausted land, a lean harvest, a recent blight.
            $causes = array_values(array_filter([
                $village->landExhausted ? $village->landExhaustedEventId : null,
                $village->leanHarvestEventId,
                $village->lastBlightYear === $date->year ? $village->lastBlightEventId : null,
            ]));
            $event = $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — famine grips %s as the granary runs dry.',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name,
            ), 'famine-onset', [], $causes, ['scarcity']);
            $village->famineEventId = $event->id;
        } elseif ($village->starvationFactor <= 1.0 && $village->inFamine) {
            $village->inFamine = false;
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — the famine at %s breaks; the granary fills again.',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name,
            ), 'famine-break', [], $village->famineEventId !== null ? [$village->famineEventId] : []);
            $village->famineEventId = null;
        }
    }
}
