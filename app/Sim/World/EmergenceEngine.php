<?php

namespace App\Sim\World;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;

/**
 * Evaluated once per in-world day. Produces the emergent, path-dependent
 * skeleton — pairings, births (with inherited traits), and deaths — and
 * records each as a canonical chronicle event.
 *
 * The vital rates are tuned for **long-run replacement**: a settlement must be able to grow into its
 * land and hold there over centuries, not merely survive its first few decades (they were once tuned
 * only for the 22-year canonical horizon, and bled slowly to extinction over longer runs). The pieces:
 * a hard but survivable childhood ({@see INFANT_MORTALITY} — well over a third of those born still die
 * before adulthood), founders seeded young (see {@see World::seedTharadosVillage()}) so the founding
 * generation has its fertile years ahead, quick pairing ({@see PAIR_CHANCE_DAY}), and a density brake
 * in {@see tryBirth()} that keeps births full until a settlement is about half-full so it fills toward
 * carrying capacity instead of stalling at a fraction of it.
 *
 * Fertility ({@see BIRTH_CHANCE_DAY}) is deliberately *moderate*, not maximal: net reproduction sits
 * comfortably above one at low density and tapers to balance near capacity. Pushing it higher backfires
 * in a multi-settlement world — the faster growth overshoots carrying capacity, and the famine that
 * follows drives sickness (and so mortality) into a spiral the settlement cannot climb back out of. A
 * gentler climb is what lets settlements consolidate and grow into towns rather than boom and collapse.
 */
final class EmergenceEngine
{
    private const ADULT_AGE = 16;

    private const FERTILE_MAX = 45;

    private const PAIR_CHANCE_DAY = 0.05;

    private const BIRTH_CHANCE_DAY = 0.0050;

    private const BIRTH_SPACING_YEARS = 2;

    private const FAMINE_SEVERITY = 2.0;

    private const SICKNESS_SEVERITY = 3.0;

    private const AID_STRENGTH = 0.6; // how far mutual aid buffers the famine die-back

    private const INFANT_MORTALITY = 0.0003; // daily death risk at birth, fading through childhood

    private const CHILD_MORTALITY_DECAY = 3.0; // years over which the infant risk fades

    private const MATERNAL_MORTALITY = 0.02; // base risk a healthy, well-fed mother dies in childbirth

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        self::tryPairing($world, $tick, $date, $world->rng);
        self::tryBirth($world, $tick, $date, $world->rng);
        self::tryDeaths($world, $tick, $date, $world->rng);
    }

    private static function tryPairing(World $world, int $tick, TharadiDate $date, Rng $rng): void
    {
        // The day's pairing roll is a function of (settlement, day), off its own sub-stream.
        $rng = $rng->stream('pair', $world->village->name, $tick);
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
        $event = $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — %s and %s become partners.',
            $date->dayOfMonth, $date->monthName, $date->year, $f->name, $m->name,
        ), 'pairing', [$f->id, $m->id]);
        $f->pairingEventId = $event->id;
        $m->pairingEventId = $event->id;
    }

    private static function tryBirth(World $world, int $tick, TharadiDate $date, Rng $rng): void
    {
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        // Density-dependent fertility: births stay full until a settlement is about half-full, then
        // taper to zero at carrying capacity — so a settlement grows into its land instead of stalling
        // at a fraction of it, while still being checked before it overshoots into famine.
        $population = count($world->livingAgents());
        $capacity = $world->village->carryingCapacity;
        $density = $capacity > 0 ? max(0.0, min(1.0, 2.0 * (1.0 - $population / $capacity))) : 1.0;

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
            // This mother's conception roll on this day is a pure function of (mother, day).
            $motherRng = $rng->stream('birth', $mother->id, $tick);
            if (! $motherRng->chance(self::BIRTH_CHANCE_DAY * $density)) {
                continue;
            }
            $father = self::byId($world, $mother->partnerId);
            if ($father === null || ! $father->alive) {
                continue;
            }
            $world->spawnChild($mother, $father, $tick, $date);
            $birthEvent = $world->chronicle->last();

            // Childbirth is perilous: a frail, ill-fed mother may not survive the birth she just gave.
            $sickness = ($mother->needs['sickness'] ?? null)?->value ?? 0.0;
            if ($motherRng->chance(self::maternalMortalityRisk($sickness, $world->village->dietQuality))) {
                $mother->alive = false;
                $mother->deathTick = $tick;
                if ($mother->partnerId !== null) {
                    $partner = self::byId($world, $mother->partnerId);
                    if ($partner !== null) {
                        $partner->partnerId = null;
                    }
                    $mother->partnerId = null;
                }
                $world->chronicle->record($tick, sprintf(
                    '%d %s, Year %d — %s dies in childbirth.',
                    $date->dayOfMonth, $date->monthName, $date->year, $mother->name,
                ), 'death', [$mother->id], $birthEvent !== null ? [$birthEvent->id] : [], ['childbirth']);
            }
        }
    }

    private static function tryDeaths(World $world, int $tick, TharadiDate $date, Rng $rng): void
    {
        // Overcrowding past carrying capacity raises mortality (famine) and pulls population back.
        $population = count($world->livingAgents());
        $capacity = $world->village->carryingCapacity;
        $famine = $population > $capacity ? 1.0 + (($population - $capacity) / $capacity) * self::FAMINE_SEVERITY : 1.0;
        // A near-empty granary compounds it (scarcity-driven die-back) — but a settlement that
        // shares (mutual aid) spreads the shortfall and loses fewer of its vulnerable.
        $starvation = self::starvationWithAid($world->village->starvationFactor, $world->village->mutualAid);

        $village = $world->village;
        $overcrowded = $population > $capacity;

        foreach ($world->livingAgents() as $agent) {
            $age = $agent->ageInYears($tick);
            // Ill health compounds mortality — the sicker an agent, the likelier the end.
            $sickness = ($agent->needs['sickness'] ?? null)?->value ?? 0.0;
            $illness = 1.0 + ($sickness / 100.0) * self::SICKNESS_SEVERITY;
            // This agent's death roll on this day is a pure function of (agent, day): suppressing a
            // shock elsewhere never shifts it, so causally-independent deaths stay byte-identical.
            if (! $rng->stream('death', $agent->id, $tick)->chance(self::dailyMortality($age) * $famine * $starvation * $illness)) {
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

            // Provenance: which pressures made this death likely — the granary, a plague, crowding, or age.
            $causes = [];
            $factors = [];
            if ($village->inFamine && $village->famineEventId !== null) {
                $causes[] = $village->famineEventId;
                $factors[] = 'famine';
            }
            if ($sickness >= 40.0) {
                $factors[] = 'illness';
                if ($village->lastPlagueEventId !== null) {
                    $causes[] = $village->lastPlagueEventId;
                }
            }
            if ($overcrowded) {
                $factors[] = 'overcrowding';
            }
            if ($age >= 50) {
                $factors[] = 'old-age';
            }
            if ($factors === []) {
                $factors[] = 'natural';
            }

            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s dies at age %d.',
                $date->dayOfMonth, $date->monthName, $date->year, $agent->name, $age,
            ), 'death', [$agent->id], $causes, $factors);
        }
    }

    /** Mutual aid spreads a famine's shortfall, so sharing settlements convert less of it into death. */
    public static function starvationWithAid(float $starvation, float $mutualAid): float
    {
        return 1.0 + ($starvation - 1.0) * max(0.0, 1.0 - $mutualAid * self::AID_STRENGTH);
    }

    /**
     * U-shaped daily mortality: a steep infant/child risk that fades through the early years, plus the
     * Gompertz-ish rise of old age — so the young and the old die, and the middle years are the safe ones.
     */
    public static function dailyMortality(int $age): float
    {
        $infancy = self::INFANT_MORTALITY * exp(-max(0, $age) / self::CHILD_MORTALITY_DECAY);
        $senescence = 0.00002 * exp(($age - 40) / 10.0);

        return min(0.02, $infancy + $senescence);
    }

    /**
     * A mother's risk of dying in childbirth — lowest when she is healthy and well-fed, rising several
     * times higher when sickness and a poor diet have left her frail. Every birth carries it (doc 05).
     */
    public static function maternalMortalityRisk(float $sickness, float $dietQuality): float
    {
        $health = max(0.0, min(1.0, 1.0 - $sickness / 100.0));
        $diet = max(0.0, min(1.0, $dietQuality));

        return self::MATERNAL_MORTALITY * (2.0 - $health) / (0.5 + 0.5 * $diet);
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
