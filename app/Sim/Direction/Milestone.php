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

    /** A soft beat whose deadline passed unmet — the world went another way (only soft beats can lapse). */
    public bool $lapsed = false;

    /**
     * @param  list<string>  $prerequisites  names of beats that must have fired before this one can —
     *                                       an authored arc, not just a single target (design doc 08).
     * @param  bool  $hard  a hard pin *must* hold — force-bridged at its deadline if emergence won't
     *                      produce it; a soft beat (the default) yields to the world and lapses instead.
     */
    public function __construct(
        public readonly string $name,
        public readonly int $deadlineYear,
        public readonly int $prereqPopulation,
        public readonly array $prerequisites = [],
        public readonly bool $hard = false,
    ) {}

    /** A hard pin that had to be forced against the world's grain — a conflict between author and emergence. */
    public function isConflict(): bool
    {
        return $this->hard && $this->wasForced;
    }
}
