<?php

namespace App\Sim\Projects;

/**
 * A collective endeavor a group works toward by a deadline. Each project has a
 * type and an initiator (an agent, a need, or a cultural norm), so the engine can
 * run many kinds of communal work — not just the recurring storm preparation —
 * through one path.
 */
final class Project
{
    public float $effort = 0.0;

    public bool $resolved = false;

    public function __construct(
        public readonly string $name,
        public readonly int $deadlineTick,
        public readonly float $requiredEffort,
        public readonly string $type = 'general',
        public readonly string $initiator = 'the community',
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
