<?php

namespace App\Sim\World;

use App\Sim\Culture\Culture;
use App\Sim\Culture\Faith;
use App\Sim\Economy\EconomyEngine;
use App\Sim\Economy\Stockpile;
use App\Sim\Institutions\Institution;
use App\Sim\Projects\Project;

/** A settlement — the smallest place-scale container of agents (Phase 0). */
final class Village
{
    /** The communal granary the settlement produces into and consumes from. */
    public Stockpile $stockpile;

    /** The institution this settlement founds once its cooperation deficit persists. */
    public ?Institution $institution = null;

    /** When folded by the LOD manager (TWT-213), the settlement's statistical stand-in; null = tracked per-agent. */
    public ?Cohort $cohort = null;

    /** A folded settlement's mean sickness (0..100) — the cohort analogue of per-agent sickness (TWT-246). */
    public float $cohortSickness = 0.0;

    /** The culture of this settlement's people; sets the cohesion baseline and institution type. Drifts with material security. */
    public Culture $culture;

    /** This settlement's region — its biome's seasonal yields, scarcity, and food basket. Null falls back to the world region (the canonical single-region run). */
    public ?RegionProfile $regionProfile = null;

    /** Map position (arbitrary units). Distance between settlements gates and taxes trade and migration (TWT-127). */
    public float $x = 0.0;

    public float $y = 0.0;

    /**
     * Which decomposition region this settlement belongs to (TWT-112) — distinct from {@see $regionProfile}
     * (its biome). The concurrency partition: settlements sharing a regionId couple daily and exactly,
     * while cross-region flows batch at the yearly sync barrier. Default 0 → a single region, so the
     * canonical run is one region and byte-identical.
     */
    public int $regionId = 0;

    /** Culture-set baseline cooperation strength (0..1), derived from the culture's collectivism. */
    public float $baselineCohesion;

    /** Cooperation strength a large, anonymous settlement still retains (the decay floor). */
    public float $cohesionFloor = 0.25;

    /** Settlement size at which "everyone knows everyone" starts to break down. */
    public int $cohesiveGroupSize = 15;

    /** Food/day the settlement's land can sustainably yield; the base of the production ceiling. Erodes with overuse. */
    public float $landYield;

    /** The land's pristine, un-degraded yield — the ceiling recovery (fallow) heals back toward. */
    public readonly float $baseLandYield;

    /** Technology multiplier on land + labor output (Boserup intensification); 1.0 = baseline. */
    public float $technology;

    /** Max sustainable population, computed from land yield × technology ÷ the per-capita ration. */
    public int $carryingCapacity;

    public ?float $lastReadiness = null;

    public int $underpreparedYears = 0;

    /** Mortality multiplier from scarcity: 1.0 when fed, higher as the granary empties. */
    public float $starvationFactor = 1.0;

    /** Whether the settlement is currently gripped by famine (drives chronicle onset/recovery). */
    public bool $inFamine = false;

    /** Consecutive years gripped by famine — sustained distress drives a settlement to send for help (TWT-184). */
    public int $famineYears = 0;

    /** Whether the settlement has emptied (died out or fled) and been mourned in the chronicle — set once. */
    public bool $collapsed = false;

    /** Chronicle id of the active famine-onset event, cited as the cause of famine deaths (null when fed). */
    public ?int $famineEventId = null;

    /** Chronicle id of the most recent plague event, cited as the cause of illness deaths. */
    public ?int $lastPlagueEventId = null;

    /** Whether the settlement is currently gripped by a spreading outbreak — latches the contagion chronicle beat (TWT-79). */
    public bool $inOutbreak = false;

    /** Chronicle id of the standing institution's founding event, cited when it collapses. */
    public ?int $institutionEventId = null;

    /** Chronicle ids of the underprepared-Sandstorm events that accumulate toward founding an institution. */
    /** @var list<int> */
    public array $underpreparedEventIds = [];

    /** Whether the land is currently exhausted below its base (drives chronicle onset/recovery). */
    public bool $landExhausted = false;

    /** Chronicle id of the active land-exhaustion event, cited when scarcity follows depleted land. */
    public ?int $landExhaustedEventId = null;

    /** The technology level last chronicled as an advance, so only notable gains are recorded. */
    public float $lastTechMilestone = 1.0;

    /** Chronicle id of this year's lean-harvest event (null when the harvest was not lean). */
    public ?int $leanHarvestEventId = null;

    /** Chronicle id of the most recent blight, and the year it struck — cited if a famine follows it. */
    public ?int $lastBlightEventId = null;

    public ?int $lastBlightYear = null;

    /** Diet quality 0..1 — the variety/nutrition of foods in season; a varied diet keeps people well. */
    public float $dietQuality = 1.0;

    /** Mutual aid 0..1 — the settlement's propensity to share in scarcity; buffers the famine die-back. */
    public float $mutualAid = 0.5;

    /** This year's harvest multiplier on production — 1.0 average, &gt;1 a bumper year, &lt;1 a lean one. */
    public float $harvestQuality = 1.0;

    /** Communal endeavors this settlement currently has underway (Sandstorm prep, director-spawned beats). */
    /** @var list<Project> */
    public array $projects = [];

    /**
     * @param  list<Agent>  $agents
     * @param  float  $landYield  Food/day the oasis can sustainably produce; sets the
     *                            carrying capacity. A fixed environmental ceiling for now;
     *                            later it varies with season, shocks, and trade.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $region,
        public array $agents = [],
        float $landYield = 40.0,
        float $technology = 1.0,
        ?Culture $culture = null,
    ) {
        $this->landYield = $landYield;
        $this->baseLandYield = $landYield;
        $this->technology = $technology;
        $this->lastTechMilestone = $technology;
        $this->culture = $culture ?? Culture::tharados();
        $this->baselineCohesion = $this->culture->baselineCohesion();
        $this->stockpile = new Stockpile;
        $this->carryingCapacity = EconomyEngine::carryingCapacityFor($landYield, $technology);
    }

    /**
     * Organic cooperation strength, derived from settlement size: a tight village
     * cooperates near its cultural baseline, a crowded one decays toward the floor.
     * As scale grows the gap between this and demand becomes the cooperation deficit
     * that institutions later step in to fill (design doc 07).
     */
    /** The settlement's faith, derived from its current culture (so it tracks cultural drift). */
    public function faith(): Faith
    {
        return Faith::fromCulture('the Way of Nara', $this->culture);
    }

    public function cohesion(int $populationSize): float
    {
        $scale = max(0, $populationSize) / $this->cohesiveGroupSize;
        $decay = 1.0 / (1.0 + $scale * $scale);

        return $this->cohesionFloor + ($this->baselineCohesion - $this->cohesionFloor) * $decay;
    }

    /** Straight-line distance to another settlement, in map units. */
    public function distanceTo(self $other): float
    {
        return hypot($this->x - $other->x, $this->y - $other->y);
    }

    /** A direction-independent key for the pair {this, other} — the canonical id for a route, relation, or feud. */
    public function pairKey(self $other): string
    {
        return $this->name < $other->name ? "{$this->name}↔{$other->name}" : "{$other->name}↔{$this->name}";
    }

    /** A tracked settlement runs per-agent (the default); a folded one advances as its {@see $cohort}. */
    public function isTracked(): bool
    {
        return $this->cohort === null;
    }

    /**
     * The settlement's living head count, scale-polymorphic (TWT-246): the tracked roster when tracked,
     * the cohort's expected population when folded. Equal to the {@see livingAgents()} count while
     * tracked, so swapping it into the cross-settlement engines leaves an all-tracked run byte-identical.
     */
    public function headcount(): float
    {
        return $this->cohort !== null ? $this->cohort->population() : (float) count($this->livingAgents());
    }

    /** @return list<Agent> the settlement's living members — empty once folded into a cohort */
    public function livingAgents(): array
    {
        if ($this->cohort !== null) {
            return [];
        }

        return array_values(array_filter($this->agents, static fn (Agent $a): bool => $a->alive));
    }

    /**
     * Fold the living individuals into a {@see Cohort} (their age distribution) and drop them from the
     * tracked roster — the LOD demotion (TWT-213/50). RNG-free; population is conserved, one cohort head
     * per living soul. The dead stay as history, and events keep their subjects in the chronicle.
     */
    public function foldIntoCohort(int $tick): void
    {
        $byAge = [];
        foreach ($this->livingAgents() as $agent) {
            $age = $agent->ageInYears($tick);
            $byAge[$age] = ($byAge[$age] ?? 0.0) + 1.0;
        }
        ksort($byAge);

        $this->cohort = new Cohort($byAge);
        $this->agents = array_values(array_filter($this->agents, static fn (Agent $a): bool => ! $a->alive));
    }

    public function hasOpenProject(): bool
    {
        foreach ($this->projects as $project) {
            if (! $project->resolved) {
                return true;
            }
        }

        return false;
    }
}
