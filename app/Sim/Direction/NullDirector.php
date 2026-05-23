<?php

namespace App\Sim\Direction;

use App\Sim\Time\TharadiDate;
use App\Sim\World\World;

/**
 * No director at all: the world runs on pure seeded emergence, with no authored milestones, pins, or
 * root events steering it. The honest baseline that proves the engine needs no narrative hand — and
 * the mode a worldbuilder picks when they want only what emerges, unauthored.
 */
final class NullDirector implements Director
{
    public function direct(World $world, int $tick, TharadiDate $date): void
    {
        // Intentionally empty — narrative intent is opt-in.
    }
}
