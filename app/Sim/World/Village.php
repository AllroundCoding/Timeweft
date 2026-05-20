<?php

namespace App\Sim\World;

/** A settlement — the smallest place-scale container of agents (Phase 0). */
final class Village
{
    /** Organic cooperation strength (0..1); high in a tight village, decays with scale. */
    public float $cohesion = 0.85;
    public ?float $lastReadiness = null;
    public int $underpreparedYears = 0;

    /** @param list<Agent> $agents */
    public function __construct(
        public readonly string $name,
        public readonly string $region,
        public array $agents = [],
    ) {}
}
