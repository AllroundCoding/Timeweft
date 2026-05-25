<?php

namespace App\Sim\World;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiDate;

/**
 * Raids and open war between hostile settlements (design doc 05/06; TWT-196) — the payoff of the
 * relations layer (TWT-125). Where {@see RelationsEngine} merely *withholds* (enemies don't trade),
 * this *acts*: a settlement that has fallen to enmity with a neighbour raids its stores, and a deeper
 * hostility flares into open war that costs lives on both sides. Raiding is the non-consensual flip
 * side of trade — both move goods between settlements; one with consent, one at spear-point. Grounded
 * in the canon hazards (Tharados raids, Aetheria border-raids, piracy).
 *
 * World-level and evaluated at the turn of the year. Its randomness is drawn from a dedicated per-pair
 * sub-stream, so it neither rides the emergence stream nor perturbs another pair — and below two
 * settlements there is nothing to fight over, so it draws nothing and the single-settlement run is
 * byte-identical. The turning points are chronicled as citable causes.
 */
final class WarEngine
{
    /** Below this standing the enmity has flared into open war — lives are lost, not just stores. */
    private const WAR_BELOW = 0.15;

    /** A hostile (but not yet warring) pair's yearly chance the stronger raids the weaker. */
    private const RAID_CHANCE_PER_YEAR = 0.4;

    /** Fraction of the victim's food and money a raid carries off. */
    private const RAID_SEIZE = 0.3;

    /** Fraction of each side's people lost in a year of open war. */
    private const WAR_CASUALTY_RATE = 0.06;

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // campaigns are reckoned at the turn of the year
        }
        $villages = $world->villages;
        $count = count($villages);
        if ($count < 2) {
            return; // no one to fight
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($world->crossRegionBarrier && RegionPartition::sameRegion($villages[$i], $villages[$j])) {
                    continue; // intra-region clashes already resolved inside the region (TWT-112)
                }
                if (RelationsEngine::hostile($world, $villages[$i], $villages[$j])) {
                    self::clash($world, $villages[$i], $villages[$j], $tick, $date);
                }
            }
        }
    }

    /** Resolve a year of conflict between two hostile settlements: open war if deep enough, else a raid. */
    private static function clash(World $world, Village $a, Village $b, int $tick, TharadiDate $date): void
    {
        $rng = $world->rng->stream('war', $a->pairKey($b), $date->year);

        if (RelationsEngine::standing($world, $a, $b) < self::WAR_BELOW) {
            self::war($world, $a, $b, $rng, $tick, $date);

            return;
        }

        if ($rng->chance(self::RAID_CHANCE_PER_YEAR)) {
            // The stronger settlement raids the weaker.
            [$raider, $victim] = $a->headcount() >= $b->headcount() ? [$a, $b] : [$b, $a];
            self::raid($world, $raider, $victim, $tick, $date);
        }
    }

    /** A raid: the raider carries off a share of the victim's stores. */
    private static function raid(World $world, Village $raider, Village $victim, int $tick, TharadiDate $date): void
    {
        $loot = [];
        foreach (['food', 'money'] as $good) {
            $taken = $victim->stockpile->withdraw($good, $victim->stockpile->amount($good) * self::RAID_SEIZE);
            $raider->stockpile->add($good, $taken);
            $loot[$good] = $taken;
        }
        if ($loot['food'] <= 0.0 && $loot['money'] <= 0.0) {
            return; // nothing worth recording
        }

        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — %s raids %s, carrying off its stores.',
            $date->dayOfMonth, $date->monthName, $date->year, $raider->name, $victim->name,
        ), 'raid', [], [], ['raid']);
    }

    /** Open war: both sides lose people, recorded as a single citable beat. */
    private static function war(World $world, Village $a, Village $b, Rng $rng, int $tick, TharadiDate $date): void
    {
        $fallen = [];
        $cohortDeaths = 0;
        foreach ([$a, $b] as $side) {
            if ($side->cohort !== null) {
                // A folded side loses its share statistically — the cohort is culled, with no named dead.
                $toll = ceil($side->cohort->population() * self::WAR_CASUALTY_RATE);
                $side->cohort = CohortEngine::cull($side->cohort, $toll);
                $cohortDeaths += (int) $toll;

                continue;
            }
            $pool = $side->livingAgents();
            $casualties = (int) ceil(count($pool) * self::WAR_CASUALTY_RATE);
            for ($k = 0; $k < $casualties && $pool !== []; $k++) {
                $index = $rng->int(0, count($pool) - 1);
                $victim = $pool[$index];
                array_splice($pool, $index, 1);
                $victim->alive = false;
                $victim->deathTick = $tick;
                $fallen[] = $victim->id;
                self::widow($side, $victim);
            }
        }
        if ($fallen === [] && $cohortDeaths === 0) {
            return;
        }

        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — war between %s and %s claims %d souls.',
            $date->dayOfMonth, $date->monthName, $date->year, $a->name, $b->name, count($fallen) + $cohortDeaths,
        ), 'war', $fallen, [], ['war']);
    }

    /** Break the partner link a war death leaves behind. */
    private static function widow(Village $village, Agent $fallen): void
    {
        if ($fallen->partnerId === null) {
            return;
        }
        foreach ($village->agents as $other) {
            if ($other->id === $fallen->partnerId) {
                $other->partnerId = null;
            }
        }
        $fallen->partnerId = null;
    }
}
