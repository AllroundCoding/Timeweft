<?php

namespace App\Sim\Direction;

/** An authored beat the story director steers toward within a time budget. */
final class Milestone
{
    public bool $achieved = false;

    public ?int $achievedTick = null;

    public bool $wasForced = false;

    /** Chronicle id of the event this beat fired, so a dependent beat can cite it as a cause. */
    public ?int $achievedEventId = null;

    /**
     * @param  list<string>  $prerequisites  names of beats that must have fired before this one can —
     *                                       an authored arc, not just a single target (design doc 08).
     */
    public function __construct(
        public readonly string $name,
        public readonly int $deadlineYear,
        public readonly int $prereqPopulation,
        public readonly array $prerequisites = [],
    ) {}
}
