<?php

namespace App\Sim\Projects;

/** A collective endeavor a group works toward by a deadline (projects v1). */
final class Project
{
    public float $effort = 0.0;
    public bool $resolved = false;

    public function __construct(
        public readonly string $name,
        public readonly int $deadlineTick,
        public readonly float $requiredEffort,
    ) {}

    public function contribute(float $amount): void
    {
        $this->effort += $amount;
    }

    /** Outcome by degree: 0..1 of the required effort met by the deadline. */
    public function readiness(): float
    {
        return $this->requiredEffort > 0.0 ? min(1.0, $this->effort / $this->requiredEffort) : 1.0;
    }
}
