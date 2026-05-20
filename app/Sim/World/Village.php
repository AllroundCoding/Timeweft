<?php

namespace App\Sim\World;

use App\Sim\Culture\Culture;
use App\Sim\Economy\Stockpile;
use App\Sim\Institutions\Institution;

/** A settlement — the smallest place-scale container of agents (Phase 0). */
final class Village
{
    /** The communal granary the settlement produces into and consumes from. */
    public Stockpile $stockpile;

    /** The institution this settlement founds once its cooperation deficit persists. */
    public ?Institution $institution = null;

    /** The culture of this settlement's people; sets the cohesion baseline and institution type. */
    public readonly Culture $culture;

    /** Culture-set baseline cooperation strength (0..1), derived from the culture's collectivism. */
    public float $baselineCohesion;

    /** Cooperation strength a large, anonymous settlement still retains (the decay floor). */
    public float $cohesionFloor = 0.25;

    /** Settlement size at which "everyone knows everyone" starts to break down. */
    public int $cohesiveGroupSize = 15;

    public ?float $lastReadiness = null;

    public int $underpreparedYears = 0;

    /**
     * @param  list<Agent>  $agents
     * @param  int  $carryingCapacity  Max sustainable population. A fixed oasis ceiling
     *                                 for now; later the output of the resource/trade system (and raised by imports).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $region,
        public array $agents = [],
        public int $carryingCapacity = 40,
        ?Culture $culture = null,
    ) {
        $this->culture = $culture ?? Culture::tharados();
        $this->baselineCohesion = $this->culture->baselineCohesion();
        $this->stockpile = new Stockpile;
    }

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
