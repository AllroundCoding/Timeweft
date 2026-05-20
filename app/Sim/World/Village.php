<?php

namespace App\Sim\World;

/** A settlement — the smallest place-scale container of agents (Phase 0). */
final class Village
{
    /** @param list<Agent> $agents */
    public function __construct(
        public readonly string $name,
        public readonly string $region,
        public array $agents = [],
    ) {}
}
