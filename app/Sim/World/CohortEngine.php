<?php

namespace App\Sim\World;

use App\Sim\Culture\Culture;
use App\Sim\Support\NameGenerator;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;

/**
 * Advances a {@see Cohort} a year at a time by the *same* demography the tracked simulation runs
 * per-agent — so a cohort is the faithful mean-field of {@see EmergenceEngine}, not a separate model.
 * Each year: the age-specific mortality of doc 05 culls the young and the old; density-dependent
 * fertility (births slow as the population nears carrying capacity) refills the cradle; everyone ages.
 *
 * Deterministic and RNG-free — a cohort carries the *expected* population, where tracked agents carry
 * a stochastic sample of it. This is the scaling primitive: the cost is O(age bands), whether the
 * cohort stands for fifty souls or fifty thousand.
 */
final class CohortEngine
{
    private const MAX_AGE = 90;

    private const FERTILE_MIN = 16; // mirrors EmergenceEngine's ADULT_AGE / FERTILE_MAX window

    private const FERTILE_MAX = 45;

    private const FEMALE_FRACTION = 0.5;

    private const PAIRED_FRACTION = 0.7; // share of fertile-age women in a union and so bearing children

    private const BIRTH_CHANCE_DAY = 0.0025; // mirrors EmergenceEngine

    private const BIRTH_SPACING_YEARS = 2; // a mother bears at most once every two years

    public static function advanceYear(Cohort $cohort, float $carryingCapacity, float $famine = 1.0): Cohort
    {
        $days = TharadiCalendar::DAYS_PER_YEAR;

        // Age-specific mortality (doc 05), compounded over the year and worsened by famine.
        $survivors = [];
        foreach ($cohort->byAge as $age => $count) {
            $dailyDeath = min(1.0, EmergenceEngine::dailyMortality($age) * $famine);
            $survivors[$age] = $count * (1.0 - $dailyDeath) ** $days;
        }

        $newborns = self::births($survivors, $carryingCapacity, $days);

        // Everyone ages a year; the cradle takes the year's newborns; the oldest band dies out.
        $aged = [0 => $newborns];
        foreach ($survivors as $age => $count) {
            if ($age + 1 <= self::MAX_AGE) {
                $aged[$age + 1] = ($aged[$age + 1] ?? 0.0) + $count;
            }
        }
        ksort($aged); // stable iteration order (a determinism invariant)

        return new Cohort($aged);
    }

    /**
     * Promote a cohort member into a fully-tracked agent (design doc 10; TWT-50) — "materialize on
     * observation" applied to *existence*. An age is drawn from the cohort's distribution, a real
     * individual of that age is born from the species/region/culture (the same generation as any
     * birth, off a per-entity sub-stream so it is reproducible), and one head leaves the cohort. The
     * inverse of {@see demote}; together they keep the population conserved across the LOD boundary.
     *
     * @return array{0: Agent, 1: Cohort} the new tracked agent and the cohort with one fewer soul
     */
    public static function promote(Cohort $cohort, Species $species, RegionProfile $region, Culture $culture, int $id, int $tick, Rng $rng, NameGenerator $names): array
    {
        $age = self::sampleAge($cohort, $rng);
        $birthTick = $tick - $age * TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;
        $agent = $species->birth($id, $birthTick, $region, $culture, $rng->stream('agent', $id), $names);

        $byAge = $cohort->byAge;
        $byAge[$age] = max(0.0, ($byAge[$age] ?? 0.0) - 1.0);

        return [$agent, new Cohort($byAge)];
    }

    /** Demote a tracked agent back into the cohort — fold the individual into the count at its age, detail discarded. */
    public static function demote(Cohort $cohort, Agent $agent, int $tick): Cohort
    {
        $age = $agent->ageInYears($tick);
        $byAge = $cohort->byAge;
        $byAge[$age] = ($byAge[$age] ?? 0.0) + 1.0;
        ksort($byAge);

        return new Cohort($byAge);
    }

    /** Draw an age from the cohort's distribution, weighted by how many people are that age. */
    private static function sampleAge(Cohort $cohort, Rng $rng): int
    {
        $byAge = $cohort->byAge;
        ksort($byAge);
        $population = array_sum($byAge);
        if ($population <= 0.0) {
            return self::FERTILE_MIN; // an empty cohort has no one to promote; a safe default age
        }

        $target = $rng->float(0.0, $population);
        $cumulative = 0.0;
        foreach ($byAge as $age => $count) {
            $cumulative += $count;
            if ($target <= $cumulative) {
                return $age;
            }
        }

        return array_key_last($byAge) ?? self::FERTILE_MIN; // float-rounding safety
    }

    /**
     * The year's newborns: fertile women in unions, bearing at a density-dependent rate capped by
     * birth spacing — the aggregate of EmergenceEngine's per-mother conception roll.
     *
     * @param  array<int,float>  $byAge
     */
    private static function births(array $byAge, float $carryingCapacity, int $days): float
    {
        $population = array_sum($byAge);
        $density = $carryingCapacity > 0.0 ? max(0.0, 1.0 - $population / $carryingCapacity) : 1.0;

        $fertileWomen = 0.0;
        foreach ($byAge as $age => $count) {
            if ($age >= self::FERTILE_MIN && $age <= self::FERTILE_MAX) {
                $fertileWomen += $count;
            }
        }
        $fertileWomen *= self::FEMALE_FRACTION * self::PAIRED_FRACTION;

        $perWoman = min(1.0 / self::BIRTH_SPACING_YEARS, self::BIRTH_CHANCE_DAY * $density * $days);

        return $fertileWomen * $perWoman;
    }
}
