<?php

namespace App\Sim\World;

/**
 * Level of detail in space (design doc 10; TWT-213): per settlement, decide whether to run the full
 * per-agent simulation (tracked) or fold it into a statistical {@see Cohort} advanced by
 * {@see CohortEngine}. Detail follows attention — as derive-on-demand (TWT-38) does in time, this does
 * in space, so per-tick cost scales with the salient cast + cohort count, not the total living
 * population (the headline scaling gap that blocks growing from a village to a civilization).
 *
 * First cut: salience is a size threshold set well above any hand-run scenario, so every existing run
 * (the canonical vaeris world included) stays fully tracked and byte-identical — folding only kicks in
 * once a settlement grows past the threshold. Promotion/demotion at the boundary conserves population.
 */
final class LodManager
{
    /**
     * A settlement with more living souls than this folds into a cohort (unless it is the focus). Set
     * far above any current scenario, so wiring LOD in is a no-op for every existing run: the canonical
     * hash holds while the world is all-tracked.
     */
    public const COHORT_THRESHOLD = 200;

    /** Tracked (per-agent, as before) iff salient: the focus settlement, or one under the cohort threshold. */
    public static function isSalient(Village $village, bool $isFocus): bool
    {
        return $isFocus || count($village->livingAgents()) <= self::COHORT_THRESHOLD;
    }

    /**
     * Reconcile each settlement's detail level against its salience: fold an oversized, non-focus,
     * still-tracked settlement into a cohort. A no-op for everything under the threshold — no state
     * changes, no RNG — so an all-tracked world advances byte-identically to before LOD existed.
     */
    public static function reconcile(World $world, int $tick): void
    {
        foreach ($world->villages as $index => $village) {
            $isFocus = $index === 0; // the primary settlement is the camera's home — always tracked
            if ($village->isTracked() && ! self::isSalient($village, $isFocus)) {
                $village->foldIntoCohort($tick);
            }
        }
    }

    /** Advance a folded settlement one year as a cohort — cost O(age bands), regardless of head count. */
    public static function advanceYear(Village $village): void
    {
        if ($village->cohort === null) {
            return;
        }

        $village->cohort = CohortEngine::advanceYear($village->cohort, (float) $village->carryingCapacity, $village->starvationFactor);
    }
}
