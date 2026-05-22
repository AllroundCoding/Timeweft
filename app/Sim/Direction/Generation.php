<?php

namespace App\Sim\Direction;

use App\Sim\Support\Rng;
use App\Sim\World\World;

/**
 * The two generation modes on one engine (design doc 08) — same causal machinery, opposite directions:
 *
 *  - **seed-forward** ("surprise me"): a fresh village from initial conditions, history left to emerge;
 *  - **end-state-backward** ("justify my Vaeris"): an authored present, decomposed into the pinned past
 *    that realizes it, then run forward.
 *
 * Both return a {@see World} the same `advance()` loop drives; only where the milestones come from differs.
 */
final class Generation
{
    /** Seed-forward: grow a world from initial conditions and let its history emerge. */
    public static function seedForward(Rng $rng, int $population = 5): World
    {
        return World::seedTharadosVillage($rng, $population);
    }

    /**
     * End-state-backward: decompose authored end-state(s) into the constraint graph that justifies
     * them and pin it onto a fresh world. Refuses an inconsistent set of facts ({@see LoreCheck}) —
     * a contradictory present yields no world, not a silently-broken one.
     */
    public static function fromEndState(Rng $rng, int $population, Waypoint ...$endState): World
    {
        $problems = LoreCheck::check(...$endState);
        if ($problems !== []) {
            throw new \InvalidArgumentException('Inconsistent lore: '.implode(' ', $problems));
        }

        $world = self::seedForward($rng, $population);

        // Fold every end-state's decomposition together, keeping the tightest deadline per waypoint.
        $milestones = [];
        foreach ($endState as $target) {
            foreach (BackwardDecomposer::decompose($target) as $milestone) {
                if (! isset($milestones[$milestone->name]) || $milestone->deadlineYear < $milestones[$milestone->name]->deadlineYear) {
                    $milestones[$milestone->name] = $milestone;
                }
            }
        }
        $world->milestones = array_values($milestones);

        return $world;
    }
}
