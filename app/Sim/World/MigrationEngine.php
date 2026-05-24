<?php

namespace App\Sim\World;

use App\Sim\Time\TharadiDate;

/**
 * Population flows between settlements (design doc 05): when a settlement crowds past its ceiling or
 * a famine grips it, its unattached adults leave for a settlement with room — coupling population to
 * the economy across the map, so boom-bust becomes a *regional* flow rather than a per-village
 * die-back. Households stay rooted; the footloose single is the historical migrant.
 *
 * World-level (it moves people *between* settlements) and evaluated at the turn of the year. With
 * fewer than two settlements there is nowhere to go, so it does nothing — and draws no RNG, leaving
 * the single-settlement run byte-identical.
 */
final class MigrationEngine
{
    private const ADULT_AGE = 16;

    private const PUSH_THRESHOLD = 0.85; // crowding past this fraction of K starts pushing people out

    private const FAMINE_PUSH = 0.5; // a famine adds this much push on top of crowding

    private const MIGRATION_RATE = 0.25; // a pushed single adult's yearly chance to leave, scaled by pressure

    private const SINGLE_ADULT_FRACTION = 0.3; // the footloose, unattached share of adults — households stay rooted

    private const MAX_AGE = 120;

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // people uproot at the turn of the year, not daily
        }
        if (count($world->villages) < 2) {
            return; // nowhere to go
        }

        foreach ($world->villages as $from) {
            $pressure = self::pushPressure($from);
            if ($pressure <= 0.0) {
                continue;
            }
            $destination = self::bestDestination($world, $from);
            if ($destination === null) {
                continue; // nowhere more inviting
            }

            if ($from->cohort !== null) {
                self::migrateFolded($world, $from, $destination, $pressure, $tick, $date);

                continue;
            }

            $leavers = [];
            foreach ($from->livingAgents() as $agent) {
                if ($agent->partnerId !== null || $agent->ageInYears($tick) < self::ADULT_AGE) {
                    continue; // households stay; the unattached adult moves
                }
                // This agent's leave-this-year roll is a pure function of (agent, year).
                if ($world->rng->stream('migration', $agent->id, $date->year)->chance($pressure * self::MIGRATION_RATE)) {
                    $leavers[] = $agent;
                }
            }
            if ($leavers === []) {
                continue;
            }

            foreach ($leavers as $agent) {
                self::relocate($from, $destination, $agent, $tick);
            }
            $count = count($leavers);
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %d soul%s leave %s for %s, chasing relief.',
                $date->dayOfMonth, $date->monthName, $date->year, $count, $count === 1 ? '' : 's', $from->name, $destination->name,
            ), 'migration', array_map(static fn (Agent $a): int => $a->id, $leavers), [], ['migration']);
        }
    }

    /** How hard a settlement pushes people out: crowding past a threshold of its ceiling, worse in famine. */
    public static function pushPressure(Village $village): float
    {
        $population = $village->headcount();
        $crowding = $village->carryingCapacity > 0 ? $population / $village->carryingCapacity : 0.0;
        $push = max(0.0, $crowding - self::PUSH_THRESHOLD);
        if ($village->inFamine) {
            $push += self::FAMINE_PUSH;
        }

        return min(1.0, $push);
    }

    /** How inviting a settlement is to a newcomer: the room below its ceiling; a famine-struck place draws no one. */
    public static function desirability(Village $village): float
    {
        if ($village->inFamine) {
            return 0.0;
        }
        $population = $village->headcount();
        $headroom = $village->carryingCapacity > 0 ? 1.0 - $population / $village->carryingCapacity : 0.0;

        return max(0.0, $headroom);
    }

    /** Distance over which a destination's pull falls to half — nearer settlements draw migrants more (TWT-127). */
    private const DISTANCE_HALF_PULL = 150.0;

    private static function bestDestination(World $world, Village $from): ?Village
    {
        $best = null;
        $bestScore = 0.0;
        foreach ($world->villages as $village) {
            if ($village === $from) {
                continue;
            }
            if (RelationsEngine::hostile($world, $from, $village)) {
                continue; // no refuge in a hostile settlement (TWT-125)
            }
            // Room draws migrants, but distance discounts the pull — a close haven beats a far frontier.
            $score = self::desirability($village) * (self::DISTANCE_HALF_PULL / (self::DISTANCE_HALF_PULL + $from->distanceTo($village)));
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $village;
            }
        }

        return $best;
    }

    private static function relocate(Village $from, Village $to, Agent $agent, int $tick): void
    {
        $from->agents = array_values(array_filter($from->agents, static fn (Agent $a): bool => $a !== $agent));
        if ($to->cohort !== null) {
            // Arriving into a folded settlement: the migrant folds into its cohort at the boundary (TWT-246).
            $to->cohort = CohortEngine::demote($to->cohort, $agent, $tick);

            return;
        }
        $to->agents[] = $agent;
    }

    /**
     * A folded source sheds migrants statistically: a share of its footloose adults leave as a cohort
     * delta, delivered to the destination at its own level of detail — promoted to tracked agents if the
     * destination is tracked, folded straight into its cohort if not. Population is conserved; the
     * folded→folded path is RNG-free, and the folded→tracked promotion draws a dedicated sub-stream.
     */
    private static function migrateFolded(World $world, Village $from, Village $to, float $pressure, int $tick, TharadiDate $date): void
    {
        $cohort = $from->cohort;
        if ($cohort === null) {
            return;
        }
        $adults = $cohort->inAgeRange(self::ADULT_AGE, self::MAX_AGE);
        $leaving = (int) floor($adults * self::SINGLE_ADULT_FRACTION * min(1.0, $pressure) * self::MIGRATION_RATE);
        if ($leaving < 1) {
            return;
        }

        if ($to->cohort !== null) {
            // folded → folded: move adult heads from the source's bands into the destination's cohort.
            [$moved, $reduced] = self::detachAdults($cohort, $leaving);
            $from->cohort = $reduced;
            $to->cohort = self::attach($to->cohort, $moved);
        } else {
            // folded → tracked: materialize the migrants as tracked agents at the destination.
            $rng = $world->rng->stream('migration-folded', $from->pairKey($to), $date->year);
            for ($k = 0; $k < $leaving; $k++) {
                $world->migrantToTracked($from, $to, $rng);
            }
        }

        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — %d soul%s leave %s for %s, chasing relief.',
            $date->dayOfMonth, $date->monthName, $date->year, $leaving, $leaving === 1 ? '' : 's', $from->name, $to->name,
        ), 'migration', [], [], ['migration']);
    }

    /**
     * Detach `$count` adult heads from a cohort, proportionally across its adult bands.
     *
     * @return array{0: array<int,float>, 1: Cohort} the moved heads by age, and the source cohort with them removed
     */
    private static function detachAdults(Cohort $cohort, int $count): array
    {
        $adultTotal = $cohort->inAgeRange(self::ADULT_AGE, self::MAX_AGE);
        if ($adultTotal <= 0.0) {
            return [[], $cohort];
        }
        $fraction = min(1.0, $count / $adultTotal);

        $moved = [];
        $byAge = $cohort->byAge;
        foreach ($byAge as $age => $headcount) {
            if ($age >= self::ADULT_AGE) {
                $take = $headcount * $fraction;
                $moved[$age] = $take;
                $byAge[$age] = $headcount - $take;
            }
        }

        return [$moved, new Cohort($byAge)];
    }

    /**
     * Add migrant heads (by age) into a destination cohort.
     *
     * @param  array<int,float>  $moved
     */
    private static function attach(Cohort $cohort, array $moved): Cohort
    {
        $byAge = $cohort->byAge;
        foreach ($moved as $age => $headcount) {
            $byAge[$age] = ($byAge[$age] ?? 0.0) + $headcount;
        }
        ksort($byAge);

        return new Cohort($byAge);
    }
}
