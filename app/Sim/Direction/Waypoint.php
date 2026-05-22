<?php

namespace App\Sim\Direction;

/**
 * An authored end-state the world must arrive at — "by Year 500 there's an
 * empire here" (design doc 08). It is the *target* of backward generation:
 * {@see BackwardDecomposer} expands it into the constraint graph of earlier
 * waypoints (a kingdom, a town, a trading post…) that must precede it.
 */
final class Waypoint
{
    public function __construct(
        public readonly string $kind,
        public readonly int $byYear,
    ) {}
}
