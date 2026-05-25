<?php

namespace App\Sim\World;

/**
 * The spatial decomposition of a world into regions (design doc 18; TWT-112): settlements are grouped by
 * their {@see Village::$regionId} so each region can advance for an epoch in isolation, exchanging only
 * slow cross-region flows (trade, migration, contagion, war) at a deterministic sync barrier.
 *
 * Pure and order-fixed: {@see regionsOf} always yields regions in ascending id, the schedule the engine
 * advances them in — so a serial run is reproducible, and a (future) parallel run merges back to the same
 * order. A default world is a single region (every settlement at id 0), so the canonical run decomposes
 * to one region and stays byte-identical.
 */
final class RegionPartition
{
    /**
     * Group a world's settlements by region, in ascending region-id order — the fixed schedule.
     *
     * @return array<int, list<Village>> regionId => its settlements
     */
    public static function regionsOf(World $world): array
    {
        $byRegion = [];
        foreach ($world->villages as $village) {
            $byRegion[$village->regionId][] = $village;
        }
        ksort($byRegion);

        return $byRegion;
    }

    /** Whether two settlements share a region — an intra-region pair couples daily; a cross-region pair waits for the barrier. */
    public static function sameRegion(Village $a, Village $b): bool
    {
        return $a->regionId === $b->regionId;
    }
}
