<?php

namespace App\Sim\World;

use App\Sim\Time\TharadiCalendar;

/**
 * Runs a world by domain decomposition (design doc 18; TWT-112): each region advances in isolation for
 * an epoch, then cross-region flows reconcile at a sync barrier, and on to the next epoch. A
 * single-region world (every settlement in the default region 0) takes the undecomposed path — byte
 * for byte identical to {@see World::advance()}.
 *
 * The region advances are independent — disjoint settlements, disjoint id blocks, per-region forked RNG
 * — so they may run in any order, or in parallel, and merge back to the same world. This runs them
 * serially in a fixed region order; a parallel runner is a later, result-preserving swap.
 */
final class RegionScheduler
{
    /** The sync-barrier cadence: regions advance a year in isolation, then exchange cross-region flows (TWT-112). */
    private const EPOCH_TICKS = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public static function advance(World $world, int $ticks): void
    {
        if ($ticks <= 0) {
            return;
        }

        if (count(RegionPartition::regionsOf($world)) < 2) {
            $world->advance($ticks); // one region — nothing to decompose, byte-identical to the plain engine

            return;
        }

        $remaining = $ticks;
        while ($remaining > 0) {
            $chunk = min(self::EPOCH_TICKS, $remaining);

            $subs = $world->splitByRegion();
            foreach ($subs as $sub) {
                $sub->advance($chunk); // isolated; order-independent (a parallel worker would do this)
            }
            $world->absorbRegions($subs);
            self::barrier($world);

            $remaining -= $chunk;
        }
    }

    /**
     * The sync barrier: with the regions merged, couple the slow flows that cross region lines — relations,
     * war, trade, caravans, contagion, migration, aid — then run the global narrative authors once on the
     * whole world. The engines run with {@see World::$crossRegionBarrier} set, so they touch only
     * inter-region pairs; the intra-region coupling already happened inside each region (TWT-112).
     */
    private static function barrier(World $world): void
    {
        $date = TharadiCalendar::fromTick($world->tick);

        $world->crossRegionBarrier = true;
        RelationsEngine::runDay($world, $world->tick, $date);
        WarEngine::runDay($world, $world->tick, $date);
        TradeEngine::runDay($world, $world->tick, $date);
        CaravanEngine::runDay($world, $world->tick, $date);
        ContagionEngine::runDay($world, $world->tick, $date);
        MigrationEngine::runDay($world, $world->tick, $date);
        DistressEngine::runDay($world, $world->tick, $date);
        $world->crossRegionBarrier = false;

        // The global authors were suppressed inside each region; run them once on the merged world.
        $world->director->direct($world, $world->tick, $date);
        foreach (WorldGuider::inspect($world, $world->tick) as $violation) {
            $world->guardLog[] = $violation;
        }
    }
}
