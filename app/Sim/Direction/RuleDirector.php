<?php

namespace App\Sim\Direction;

use App\Sim\Time\TharadiDate;
use App\Sim\World\World;

/**
 * The default, no-LLM director: a human authors the world's milestones and pins, and this evaluates
 * them by rule each day — steering soft beats that lapse if emergence won't reach them and forcing
 * hard, canon-pinned ones. Each beat steers from its own RNG sub-stream, so authoring an arc never
 * reshuffles the emergent world (TWT-39/107). The reproducible baseline an LLM director swaps for.
 */
final class RuleDirector implements Director
{
    public function direct(World $world, int $tick, TharadiDate $date): void
    {
        foreach ($world->milestones as $milestone) {
            StoryDirector::evaluate($world, $milestone, $tick, $date, $world->rng->stream('director', $milestone->name, $tick));
        }
    }
}
