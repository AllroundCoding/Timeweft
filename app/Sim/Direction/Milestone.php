<?php

namespace App\Sim\Direction;

/** An authored beat the story director steers toward within a time budget. */
final class Milestone
{
    public bool $achieved = false;
    public ?int $achievedTick = null;
    public bool $wasForced = false;

    public function __construct(
        public readonly string $name,
        public readonly int $deadlineYear,
        public readonly int $prereqPopulation,
    ) {}
}
