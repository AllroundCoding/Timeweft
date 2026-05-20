<?php

namespace App\Sim\World;

/** A settlement — the smallest place-scale container of agents (Phase 0). */
final class Village
{
    /** Culture-set baseline cooperation strength (0..1): communal high, selfish low. */
    public float $baselineCohesion = 0.85;
    /** Cooperation strength a large, anonymous settlement still retains (the decay floor). */
    public float $cohesionFloor = 0.25;
    /** Settlement size at which "everyone knows everyone" starts to break down. */
    public int $cohesiveGroupSize = 15;
    public ?float $lastReadiness = null;
    public int $underpreparedYears = 0;

    /**
     * @param list<Agent> $agents
     * @param int $carryingCapacity Max sustainable population. A fixed oasis ceiling
     *   for now; later the output of the resource/trade system (and raised by imports).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $region,
        public array $agents = [],
        public int $carryingCapacity = 40,
    ) {}

    /**
     * Organic cooperation strength, derived from settlement size: a tight village
     * cooperates near its cultural baseline, a crowded one decays toward the floor.
     * As scale grows the gap between this and demand becomes the cooperation deficit
     * that institutions later step in to fill (design doc 07).
     */
    public function cohesion(int $populationSize): float
    {
        $scale = max(0, $populationSize) / $this->cohesiveGroupSize;
        $decay = 1.0 / (1.0 + $scale * $scale);

        return $this->cohesionFloor + ($this->baselineCohesion - $this->cohesionFloor) * $decay;
    }
}
