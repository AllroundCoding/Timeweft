<?php

namespace App\Sim\World;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;

/**
 * Evaluated once per in-world day. Produces the emergent, path-dependent
 * skeleton — pairings, births (with inherited traits), and deaths — and
 * records each as a canonical chronicle event.
 */
final class EmergenceEngine
{
    private const ADULT_AGE = 16;

    private const FERTILE_MAX = 45;

    private const PAIR_CHANCE_DAY = 0.02;

    private const BIRTH_CHANCE_DAY = 0.0025;

    private const BIRTH_SPACING_YEARS = 2;

    private const FAMINE_SEVERITY = 2.0;

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        self::tryPairing($world, $tick, $date, $world->rng);
        self::tryBirth($world, $tick, $date, $world->rng);
        self::tryDeaths($world, $tick, $date, $world->rng);
    }

    private static function tryPairing(World $world, int $tick, TharadiDate $date, Rng $rng): void
    {
        if (! $rng->chance(self::PAIR_CHANCE_DAY)) {
            return;
        }

        $singles = array_filter(
            $world->livingAgents(),
            fn (Agent $a) => $a->partnerId === null && $a->ageInYears($tick) >= self::ADULT_AGE,
        );
        $females = array_values(array_filter($singles, fn (Agent $a) => $a->sex === 'f'));
        $males = array_values(array_filter($singles, fn (Agent $a) => $a->sex === 'm'));

        if ($females === [] || $males === []) {
            return;
        }

        $f = $rng->pick($females);
        $m = $rng->pick($males);
        if (self::areKin($f, $m)) {
            return;
        }

        $f->partnerId = $m->id;
        $m->partnerId = $f->id;
        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — %s and %s become partners.',
            $date->dayOfMonth, $date->monthName, $date->year, $f->name, $m->name,
        ));
    }

    private static function tryBirth(World $world, int $tick, TharadiDate $date, Rng $rng): void
    {
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        // Density-dependent fertility: births slow as the population nears carrying capacity.
        $population = count($world->livingAgents());
        $capacity = $world->village->carryingCapacity;
        $density = $capacity > 0 ? max(0.0, 1.0 - $population / $capacity) : 1.0;

        foreach ($world->livingAgents() as $mother) {
            if ($mother->sex !== 'f' || $mother->partnerId === null) {
                continue;
            }
            $age = $mother->ageInYears($tick);
            if ($age < self::ADULT_AGE || $age > self::FERTILE_MAX) {
                continue;
            }
            if ($mother->lastBirthTick !== null && ($tick - $mother->lastBirthTick) < self::BIRTH_SPACING_YEARS * $ticksPerYear) {
                continue;
            }
            if (! $rng->chance(self::BIRTH_CHANCE_DAY * $density)) {
                continue;
            }
            $father = self::byId($world, $mother->partnerId);
            if ($father === null || ! $father->alive) {
                continue;
            }
            $world->spawnChild($mother, $father, $tick, $date);
        }
    }

    private static function tryDeaths(World $world, int $tick, TharadiDate $date, Rng $rng): void
    {
        // Overcrowding past carrying capacity raises mortality (famine) and pulls population back.
        $population = count($world->livingAgents());
        $capacity = $world->village->carryingCapacity;
        $famine = $population > $capacity ? 1.0 + (($population - $capacity) / $capacity) * self::FAMINE_SEVERITY : 1.0;
        // A near-empty granary compounds it (scarcity-driven die-back).
        $starvation = $world->village->starvationFactor;

        foreach ($world->livingAgents() as $agent) {
            $age = $agent->ageInYears($tick);
            if (! $rng->chance(self::dailyMortality($age) * $famine * $starvation)) {
                continue;
            }

            $agent->alive = false;
            $agent->deathTick = $tick;
            if ($agent->partnerId !== null) {
                $partner = self::byId($world, $agent->partnerId);
                if ($partner !== null) {
                    $partner->partnerId = null;
                }
                $agent->partnerId = null;
            }
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s dies at age %d.',
                $date->dayOfMonth, $date->monthName, $date->year, $agent->name, $age,
            ));
        }
    }

    /** Gompertz-ish daily mortality, rising with age. */
    private static function dailyMortality(int $age): float
    {
        return min(0.02, 0.00002 * exp(($age - 40) / 10.0));
    }

    private static function areKin(Agent $a, Agent $b): bool
    {
        if (in_array($b->id, $a->parentIds, true) || in_array($a->id, $b->parentIds, true)) {
            return true; // parent / child
        }
        if ($a->parentIds !== [] && $a->parentIds === $b->parentIds) {
            return true; // full siblings
        }

        return false;
    }

    private static function byId(World $world, int $id): ?Agent
    {
        foreach ($world->village->agents as $agent) {
            if ($agent->id === $id) {
                return $agent;
            }
        }

        return null;
    }
}
