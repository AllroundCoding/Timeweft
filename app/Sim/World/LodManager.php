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
     * A settlement with more living souls than this folds into a cohort once attention leaves it. Set far
     * above any current scenario, so wiring LOD in is a no-op for every existing run: the canonical hash
     * holds while the world is all-tracked.
     */
    public const COHORT_THRESHOLD = 200;

    /**
     * Whether a settlement currently holds attention: the primary settlement (the camera's home, always
     * tracked) or one the boundary has marked salient — the renderer's focus, a director pin, the player's
     * seat (TWT-248). Attention is supplied from outside ({@see World::$salient}), never sensed here, so
     * the core stays pure.
     *
     * @param  array<string,true>  $salient  the set of salient settlement names
     */
    public static function isSalient(Village $village, bool $isFocus, array $salient): bool
    {
        return $isFocus || isset($salient[$village->name]);
    }

    /**
     * Reconcile each settlement's detail level against attention, both ways (TWT-213 fold + TWT-248
     * promote): an oversized settlement no longer attended folds into a cheap cohort, and a folded
     * settlement that regains attention materializes back into tracked individuals. Detail follows
     * attention. With nothing marked salient and everything under the threshold this changes no state and
     * draws no RNG, so an all-tracked world (the canonical run) advances byte-identically to before LOD.
     */
    public static function reconcile(World $world, int $tick): void
    {
        $salient = $world->salient;
        foreach ($world->villages as $index => $village) {
            $attended = self::isSalient($village, $index === 0, $salient);

            if ($village->isTracked()) {
                // Demote: an oversized settlement no one is watching folds into a statistical cohort.
                if (! $attended && count($village->livingAgents()) > self::COHORT_THRESHOLD) {
                    $village->foldIntoCohort($tick);
                }
            } elseif ($attended) {
                // Promote: attention has reached a folded settlement — bring its people back as individuals.
                $world->materialize($village);
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
