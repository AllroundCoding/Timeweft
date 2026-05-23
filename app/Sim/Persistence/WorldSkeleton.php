<?php

namespace App\Sim\Persistence;

use App\Sim\Behavior\Activity;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Direction\Milestone;
use App\Sim\World\Village;
use App\Sim\World\World;

/**
 * The canonical, persistable state of a world — the **skeleton** (design doc 01) made concrete, the seam
 * persistence (TWT-28/30) and checkpoints (TWT-32) work against. It answers "what must be stored?": the
 * seed it is reproducible from, the canonical clock, the timeline, and the path-dependent entities and
 * ledgers you can't regenerate from the seed alone.
 *
 * What is *absent* is texture ({@see Texture}): an agent's per-tick {@see Activity} and
 * the exact value of its needs at an arbitrary tick are derived on demand, never carried here. A
 * skeleton plus the seed is enough to replay the texture between checkpoints.
 *
 * A view over the live world's canonical state, not a deep copy — {@see World::skeleton}
 * builds it; TWT-30 maps it to storage, TWT-32 anchors it to a tick as a checkpoint.
 */
readonly class WorldSkeleton implements Skeleton
{
    /**
     * @param  list<ChronicleEvent>  $chronicle  the canonical timeline
     * @param  list<Village>  $villages  the settlements and their people (canonical identity; per-tick texture excluded)
     * @param  list<Milestone>  $milestones  the authored beats this world is steered toward
     * @param  array<string,float>  $relations  inter-settlement standing ledger, keyed by settlement pair
     * @param  array<string,array{ageYears:int,lastYear:int}>  $routes  trade-route maturity ledger, keyed by settlement pair
     */
    public function __construct(
        public int $seed,
        public int $tick,
        public array $chronicle,
        public array $villages,
        public array $milestones,
        public array $relations,
        public array $routes,
    ) {}
}
