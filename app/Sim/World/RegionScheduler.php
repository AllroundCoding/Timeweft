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

            $remaining -= $chunk;
        }
    }
}
