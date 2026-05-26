<?php

namespace App\Sim\World;

use App\Sim\Behavior\BehaviorEngine;
use App\Sim\Behavior\FestivalCalendar;
use App\Sim\Causality\Intervention;
use App\Sim\Chronicle\Chronicle;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Culture\Culture;
use App\Sim\Culture\CultureEngine;
use App\Sim\Culture\Legend;
use App\Sim\Culture\LegendEngine;
use App\Sim\Direction\Director;
use App\Sim\Direction\Generation;
use App\Sim\Direction\Milestone;
use App\Sim\Direction\RuleDirector;
use App\Sim\Economy\EconomyEngine;
use App\Sim\Economy\GoodRegistry;
use App\Sim\Economy\JobMarket;
use App\Sim\Economy\ProfessionEngine;
use App\Sim\Economy\RecipeBook;
use App\Sim\Institutions\InstitutionEngine;
use App\Sim\Persistence\Checkpoint;
use App\Sim\Persistence\WorldSkeleton;
use App\Sim\Projects\ProjectEngine;
use App\Sim\Support\NameGenerator;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;
use App\Sim\Worldgen\Biome;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\SettlementSite;
use App\Sim\Worldgen\SettlementTier;

/** Top-level sim container: the canonical clock, the world's agents, RNG, and chronicle. */
final class World
{
    private const ADULT_AGE = 16;

    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    /** Stride between regions' agent- and event-id blocks when decomposed — wide enough that no region's epoch births or events overrun into the next (TWT-112). */
    private const ID_BLOCK = 1_000_000_000;

    public int $tick = 0;

    /** The settlement currently in focus — the engines operate on it; reset to the primary after a run. */
    public Village $village;

    /** @var list<Village> every settlement in the world (the village above is whichever is being simulated) */
    public array $villages = [];

    /**
     * Settlements the boundary has marked salient, by name — the attention the LOD manager promotes a
     * folded settlement back to tracked for (TWT-248). Supplied from outside (renderer focus, the player's
     * seat, a director pin); empty by default, so an all-tracked run is untouched.
     *
     * @var array<string,true>
     */
    public array $salient = [];

    public Chronicle $chronicle;

    public Species $species;

    public RegionProfile $region;

    /** The goods this world knows — each a name → stat vector (nutrition, value, perishability). */
    public GoodRegistry $goods;

    /** The recipes the settlement can cook from its goods. */
    public RecipeBook $recipes;

    /** @var list<Milestone> */
    public array $milestones = [];

    /**
     * In-world legends the chronicle's turning points have passed into (TWT-143) — a separate, additive
     * corpus from the factual record, mythologised and drifting with age.
     *
     * @var list<Legend>
     */
    public array $legends = [];

    /** High-water mark: the tick through which the chronicle has been weighed for legends (TWT-143). */
    public int $legendsThroughTick = 0;

    /** The narrative author steering the world (pluggable). Defaults to the rule-based, human-authored director; swap NullDirector for pure emergence (TWT-89). */
    public Director $director;

    /** When false, the global narrative authors (director + world guider) are suppressed: set on a decomposed sub-world, which advances its region in isolation while the authors run once on the merged world at the barrier (TWT-112). */
    public bool $worldAuthorsEnabled = true;

    /** True only during the sync barrier (TWT-112): the cross-settlement engines then couple solely inter-region pairs — intra-region pairs were already advanced inside each region. False everywhere else, so a normal run is unchanged. */
    public bool $crossRegionBarrier = false;

    /** Invariant breaches the world guider has flagged or clamped this run (TWT-90); empty on a healthy run. */
    /** @var list<GuardViolation> */
    public array $guardLog = [];

    /** Trade-route maturity, keyed "A↔B" → ['ageYears'=>int, 'lastYear'=>int]: a route reaches farther and loses less the longer it runs (TWT-127). */
    /** @var array<string,array{ageYears:int,lastYear:int}> */
    public array $routes = [];

    /** Inter-settlement relations, keyed "A↔B" → standing 0 (hostile) .. 1 (allied); drifts with kinship and competition (TWT-125). */
    /** @var array<string,float> */
    public array $relations = [];

    /** An optional retroactive edit replayed into this run (suppresses a recorded shock); null = the true history. */
    public ?Intervention $intervention = null;

    private NameGenerator $names;

    private int $nextId = 1;

    private ?string $lastFestivalKey = null;

    public function __construct(public readonly Rng $rng)
    {
        $this->chronicle = new Chronicle;
        $this->director = new RuleDirector;
    }

    public static function seedTharadosVillage(Rng $rng, int $population = 6): self
    {
        $world = new self($rng);
        $world->species = Species::vulpini();
        $world->region = RegionProfile::tharados();
        $world->goods = GoodRegistry::tharados();
        $world->recipes = RecipeBook::tharados();
        $world->names = NameGenerator::vaeris();
        // Culture is generated from the region's materials first, so it can shape the founders it births.
        $culture = Culture::fromMaterialConditions($world->region->cultureName(), $world->region->scarcity(), $world->region->seasonalVolatility(), $world->region->landTenureConcentration());
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        $agents = [];
        for ($i = 0; $i < $population; $i++) {
            // Each founder's age and traits are a pure function of its id, drawn off a per-entity
            // sub-stream so adding/removing a draw elsewhere never shifts who is born here (TWT-118).
            $id = $world->nextId++;
            $entityRng = $rng->stream('agent', $id);
            // Founders are young adults — most of the fertile window (to 45) still ahead — so a fresh
            // settlement can actually grow rather than age out before its first generation matures.
            $birthTick = -$entityRng->int(18, 35) * $ticksPerYear;
            $agents[] = $world->species->birth($id, $birthTick, $world->region, $culture, $entityRng, $world->names);
        }

        $world->village = new Village('Sunwell Oasis', $world->region->name, $agents, landYield: 22.0, culture: $culture);
        $world->village->regionProfile = $world->region;
        $world->villages = [$world->village];
        $world->milestones[] = new Milestone(
            name: 'trading post on the caravan road',
            deadlineYear: 12,
            prereqPopulation: 12,
            hard: true, // a pinned beat of Vaeris canon — force-bridged if emergence won't reach it
        );

        return $world;
    }

    /**
     * Seed-from-worldgen (TWT-82): build a live world whose settlements emerge from procedural geography
     * instead of the hand-placed Sunwell Oasis. Each sited settlement is founded on the same engine — its
     * region archetype from the biome, its land yield from the local fertility, its founding size from the
     * site's tier — and the existing economy/trade/population machinery grows them from there. An opt-in
     * path ({@see Generation::fromWorldgen()}); the canonical seeded run is untouched.
     *
     * @param  list<SettlementSite>  $sites
     */
    public static function seedFromWorldgen(Rng $rng, Climate $climate, array $sites): self
    {
        $world = new self($rng);
        $world->species = Species::vulpini();
        $world->region = RegionProfile::tharados(); // the fallback world region; each village carries its own
        $world->goods = GoodRegistry::tharados();
        $world->recipes = RecipeBook::tharados();
        $world->names = NameGenerator::vaeris();

        foreach ($sites as $site) {
            $world->foundVillage(
                population: self::foundingPopulation($site->tier),
                landYield: self::landYieldFor($climate->fertilityAt($site->x, $site->y)),
                archetype: self::archetypeForBiome($climate->biomeAt($site->x, $site->y)),
                x: (float) $site->x,
                y: (float) $site->y,
            );
        }

        if ($world->villages === []) {
            $world->foundVillage(archetype: RegionArchetype::sownland()); // a world that sited nothing still needs a start
        }

        $world->village = $world->villages[0];

        return $world;
    }

    /** Map a biome to its nearest region archetype — arid land breeds the desert culture, the rest the temperate sownland. */
    private static function archetypeForBiome(Biome $biome): RegionArchetype
    {
        return match ($biome) {
            Biome::Desert, Biome::Shrubland => RegionArchetype::desert(),
            default => RegionArchetype::sownland(),
        };
    }

    /** Land yield (food/day) from local fertility (0..1): a richer site feeds more, lifting its carrying capacity. */
    private static function landYieldFor(float $fertility): float
    {
        return 12.0 + 30.0 * max(0.0, min(1.0, $fertility));
    }

    /** Founding population by tier — a prime site starts larger; the sim grows it from there. */
    private static function foundingPopulation(SettlementTier $tier): int
    {
        return match ($tier) {
            SettlementTier::City => 12,
            SettlementTier::Town => 9,
            SettlementTier::Village => 6,
            SettlementTier::Hamlet => 4,
        };
    }

    /**
     * Found a further settlement in this world — fresh founders run by the same engine alongside the
     * others. The seam multi-settlement trade and migration build on. Given a region archetype, the
     * settlement is a different biome: its founders adapt to that land, its culture is generated from
     * that land's materials, and it grows a different basket — so it specializes apart from its
     * neighbours. Without one it defaults to the world's region (the canonical run is unchanged).
     *
     * A null name is coined from the settlement's culture (a per-settlement sub-stream) rather than
     * defaulting to a canon string — canon scenarios pin a name by passing one (TWT-120).
     */
    public function foundVillage(?string $name = null, int $population = 5, float $landYield = 22.0, ?RegionArchetype $archetype = null, ?float $x = null, ?float $y = null): Village
    {
        $region = $archetype?->toRegionProfile() ?? $this->region;
        $cultureName = $region->cultureName();
        $culture = Culture::fromMaterialConditions($cultureName, $region->scarcity(), $region->seasonalVolatility(), $region->landTenureConcentration());
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        // An unnamed settlement is christened in its own culture's voice, off a per-settlement stream.
        $name ??= $this->names->place($this->rng->stream('placename', count($this->villages)), $cultureName);

        $agents = [];
        for ($i = 0; $i < $population; $i++) {
            $id = $this->nextId++;
            $entityRng = $this->rng->stream('agent', $id);
            // Founders are young adults — most of the fertile window (to 45) still ahead — so a fresh
            // settlement can actually grow rather than age out before its first generation matures.
            $birthTick = -$entityRng->int(18, 35) * $ticksPerYear;
            $agents[] = $this->species->birth($id, $birthTick, $region, $culture, $entityRng, $this->names);
        }

        $village = new Village($name, $region->name, $agents, landYield: $landYield, culture: $culture);
        $village->regionProfile = $region;
        // Place it on the map: an explicit position (canon/scenario) or a deterministic site off a
        // dedicated sub-stream, so siting never perturbs the seeded births and deaths (TWT-127).
        $siting = $this->rng->stream('site', $village->name);
        $village->x = $x ?? $siting->float(-100.0, 100.0);
        $village->y = $y ?? $siting->float(-100.0, 100.0);
        $this->villages[] = $village;

        // A new biome brings its own foodstuffs into the world catalog (grain and water are shared).
        if ($archetype !== null) {
            foreach ($archetype->goods as $good) {
                $this->goods->define($good);
            }
            foreach ($archetype->recipes as $recipe) {
                $this->recipes->add($recipe);
            }
        }

        return $village;
    }

    public function advance(int $ticks): void
    {
        for ($i = 0; $i < $ticks; $i++) {
            $this->tick++;
            $date = TharadiCalendar::fromTick($this->tick);
            $festival = FestivalCalendar::on($date);

            if ($festival !== null) {
                $this->logFestivalOnce($date, $festival);
            }

            $seasonMultiplier = $date->season === 'Sandstorm' ? 1.4 : 1.0;

            // Level of detail (TWT-213): once a year, fold any settlement that has outgrown the salience
            // threshold into a cohort. A no-op while every settlement is tracked, so an all-tracked world
            // (the canonical run) advances byte-identically.
            if ($this->tick % self::TICKS_PER_YEAR === 8) {
                LodManager::reconcile($this, $this->tick);
            }

            // Each settlement is simulated in turn; the engines read whichever is currently in focus.
            foreach ($this->villages as $village) {
                $this->village = $village;

                if (! $village->isTracked()) {
                    // Folded: advance once a year as a cohort (O(age bands)); skip the per-agent day.
                    if ($this->tick % self::TICKS_PER_YEAR === 8) {
                        LodManager::advanceYear($village);
                    }

                    continue;
                }

                // An emptied settlement is mourned once (DistressEngine, world-level) and then simulated
                // no more — no harvests, blights, or land/tech beats over a graveyard (TWT-253). The
                // world-level engines below still run, so in-migration could later repopulate it.
                if ($village->livingAgents() === []) {
                    continue;
                }

                $projectOpen = $village->hasOpenProject();
                foreach ($village->livingAgents() as $agent) {
                    $contributing = $projectOpen && $agent->ageInYears($this->tick) >= self::ADULT_AGE;
                    $activity = BehaviorEngine::derive($agent, $date, $festival !== null, $contributing);
                    $agent->activity = $activity;
                    BehaviorEngine::applyEffects($agent, $activity, $seasonMultiplier);
                }

                // Economy, emergence, and projects run once per in-world day, per settlement.
                if ($date->hour === 8) {
                    EconomyEngine::runDay($this, $this->tick, $date);
                    CultureEngine::runDay($this, $this->tick, $date);
                    HealthEngine::runDay($this, $this->tick);
                    JobMarket::runDay($this, $this->tick);
                    ProfessionEngine::runDay($this);
                    EmergenceEngine::runDay($this, $this->tick, $date);
                    ProjectEngine::runDay($this, $this->tick, $date);
                    InstitutionEngine::runDay($this, $this->tick, $date);
                    ShockEngine::runDay($this, $this->tick, $date);
                }
            }

            // World-level steps — story direction and cross-settlement migration — once a day.
            if ($date->hour === 8) {
                // The global narrative authors run on the whole world; a decomposed sub-world suppresses
                // them and lets the barrier run them once on the merged world (TWT-112).
                if ($this->worldAuthorsEnabled) {
                    $this->director->direct($this, $this->tick, $date);
                }
                RelationsEngine::runDay($this, $this->tick, $date);
                WarEngine::runDay($this, $this->tick, $date);
                TradeEngine::runDay($this, $this->tick, $date);
                CaravanEngine::runDay($this, $this->tick, $date);
                ContagionEngine::runDay($this, $this->tick, $date);
                MigrationEngine::runDay($this, $this->tick, $date);
                DistressEngine::runDay($this, $this->tick, $date);

                // The world guider checks the day's invariants and clamps any out-of-bounds state — a
                // no-op on a well-behaved run, a safety floor when an edit or new system pushes too far.
                if ($this->worldAuthorsEnabled) {
                    foreach (WorldGuider::inspect($this, $this->tick) as $violation) {
                        $this->guardLog[] = $violation;
                    }
                    // The chronicle's turning points pass into legend (TWT-143) — a global author reading
                    // the whole history; additive, byte-identical (its own forked stream, never the chronicle).
                    LegendEngine::runDay($this, $this->tick, $date);
                }
            }
        }

        // Leave the cursor on the primary settlement for inspection (reports, queries).
        if ($this->villages !== []) {
            $this->village = $this->villages[0];
        }
    }

    /**
     * Split this world into one sub-world per region for isolated, parallelisable advance (TWT-112).
     * Each sub-world is a deep copy holding only its region's settlements, with a disjoint agent- and
     * event-id block — so births and events never collide on merge — and the global authors disabled
     * (they run once on the merged world at the barrier). The shared seed is copied intact, so each
     * region draws its own entity/pair-keyed sub-streams independently and deterministically.
     *
     * @return array<int, self> regionId => sub-world, ascending by region id (the fixed merge order)
     */
    public function splitByRegion(): array
    {
        $agentBase = $this->nextId;
        $eventBase = $this->chronicle->nextId();

        $subs = [];
        $block = 0;
        foreach (array_keys(RegionPartition::regionsOf($this)) as $regionId) {
            $sub = unserialize(serialize($this));
            assert($sub instanceof self);
            $sub->villages = array_values(array_filter($sub->villages, static fn (Village $v): bool => $v->regionId === $regionId));
            $sub->village = $sub->villages[0] ?? $sub->village;
            $sub->nextId = $agentBase + $block * self::ID_BLOCK;
            $sub->chronicle = new Chronicle($eventBase + $block * self::ID_BLOCK);
            $sub->milestones = [];
            $sub->guardLog = [];
            $sub->worldAuthorsEnabled = false;
            $subs[$regionId] = $sub;
            $block++;
        }

        return $subs;
    }

    /**
     * Merge advanced sub-worlds back into this world (TWT-112): re-collect their settlements, fold their
     * epoch events into the chronicle in a deterministic (tick, then ascending region) order, adopt each
     * region's evolved relations and routes, and lift the id counters past everything allocated. Region
     * order is fixed, so the merge is independent of which sub-world finished first — a parallel run
     * reduces to the serial result.
     *
     * @param  array<int, self>  $subs  regionId => advanced sub-world
     */
    public function absorbRegions(array $subs): void
    {
        ksort($subs); // ascending region id — the fixed, order-independent merge schedule

        $relationsBefore = $this->relations;
        $routesBefore = $this->routes;

        $this->villages = [];
        $maxAgentId = $this->nextId - 1;
        $regionOrder = 0;
        /** @var list<array{tick:int,region:int,seq:int,event:ChronicleEvent}> $epochEvents */
        $epochEvents = [];

        foreach ($subs as $sub) {
            foreach ($sub->villages as $village) {
                $this->villages[] = $village;
            }
            $seq = 0;
            foreach ($sub->chronicle->all() as $event) {
                $epochEvents[] = ['tick' => $event->tick, 'region' => $regionOrder, 'seq' => $seq, 'event' => $event];
                $seq++;
            }
            // A region evolved only its own (name-keyed, disjoint) intra-region pairs; adopt those, and
            // leave inter-region pairs at their pre-epoch value for the barrier to reconcile.
            foreach ($sub->relations as $key => $value) {
                if (! array_key_exists($key, $relationsBefore) || $relationsBefore[$key] !== $value) {
                    $this->relations[$key] = $value;
                }
            }
            foreach ($sub->routes as $key => $value) {
                if (! array_key_exists($key, $routesBefore) || $routesBefore[$key] !== $value) {
                    $this->routes[$key] = $value;
                }
            }
            $maxAgentId = max($maxAgentId, $sub->nextId - 1);
            $this->tick = $sub->tick;
            $regionOrder++;
        }

        usort($epochEvents, static fn (array $a, array $b): int => [$a['tick'], $a['region'], $a['seq']] <=> [$b['tick'], $b['region'], $b['seq']]);
        foreach ($epochEvents as $entry) {
            $this->chronicle->append($entry['event']);
        }

        $this->nextId = $maxAgentId + 1;
        $this->village = $this->villages[0] ?? $this->village;
    }

    public function spawnChild(Agent $mother, Agent $father, int $birthTick, TharadiDate $date): Agent
    {
        // The child's inherited traits are a pure function of its id, off a per-entity sub-stream.
        $childId = $this->nextId++;
        $child = $this->species->breed($childId, $mother, $father, $birthTick, $this->rng->stream('agent', $childId), $this->names, $this->village->culture->name);
        $this->village->agents[] = $child;
        $mother->lastBirthTick = $birthTick;

        $this->chronicle->record($birthTick, sprintf(
            '%d %s, Year %d — %s is born to %s and %s.',
            $date->dayOfMonth, $date->monthName, $date->year, $child->name, $mother->name, $father->name,
        ), 'birth', [$child->id, $mother->id, $father->id], array_values(array_filter([$mother->pairingEventId])));

        return $child;
    }

    /**
     * Declare which settlements currently hold attention, by name — replacing the prior set. The salience
     * signal the LOD manager reads to promote a folded settlement back to tracked (TWT-248), supplied by
     * the boundary (renderer focus, the player's seat, a director pin). The primary settlement is always
     * attended regardless.
     */
    public function setSalient(string ...$names): void
    {
        $this->salient = array_fill_keys($names, true);
    }

    /**
     * Materialize a folded settlement back into tracked individuals (LOD promotion; TWT-213/50) — the
     * inverse of {@see Village::foldIntoCohort}. Draws fresh agents from the cohort's age distribution
     * off a dedicated sub-stream (so it never perturbs a tracked run's seeded draws), conserving
     * population. For when a cohort settlement becomes salient (e.g. the focus moves to it).
     */
    public function materialize(Village $village): void
    {
        if ($village->cohort === null) {
            return;
        }

        $region = $village->regionProfile ?? $this->region;
        $cohort = $village->cohort;
        $count = (int) round($cohort->population());
        $rng = $this->rng->stream('lod-materialize', $village->name);

        for ($k = 0; $k < $count; $k++) {
            [$agent, $cohort] = CohortEngine::promote($cohort, $this->species, $region, $village->culture, $this->nextId++, $this->tick, $rng, $this->names);
            $village->agents[] = $agent;
        }

        $village->cohort = null;
    }

    /**
     * Promote one migrant from a folded source settlement's cohort into a tracked agent at the
     * destination (the LOD migration boundary; TWT-246/50). Draws off the given sub-stream so it never
     * perturbs a tracked run; conserves population — the source cohort loses a head, the destination
     * gains the agent.
     */
    public function migrantToTracked(Village $from, Village $to, Rng $rng): void
    {
        if ($from->cohort === null) {
            return;
        }
        $region = $to->regionProfile ?? $this->region;
        [$agent, $cohort] = CohortEngine::promote($from->cohort, $this->species, $region, $to->culture, $this->nextId++, $this->tick, $rng, $this->names);
        $from->cohort = $cohort;
        $to->agents[] = $agent;
    }

    /**
     * The world's canonical, persistable state (design doc 01; TWT-31) — the skeleton the rest of the
     * world's texture is derived from. A view, not a copy: persistence (TWT-28/30) maps it to storage and
     * a checkpoint (TWT-32) anchors it to this tick; the per-tick texture (activities, need values) is
     * left out, to be re-derived on demand (TWT-38).
     */
    public function skeleton(): WorldSkeleton
    {
        return new WorldSkeleton(
            seed: $this->rng->seed(),
            tick: $this->tick,
            chronicle: $this->chronicle->all(),
            villages: $this->villages,
            milestones: $this->milestones,
            relations: $this->relations,
            routes: $this->routes,
        );
    }

    /**
     * Snapshot the world's boundary state at the current tick (design doc 01; TWT-32) — a deep, immutable
     * copy from which history replays deterministically. The live world may keep advancing afterward
     * without disturbing the checkpoint.
     */
    public function checkpoint(): Checkpoint
    {
        return Checkpoint::of($this);
    }

    /** @return list<Agent> the living members of the settlement currently in focus */
    public function livingAgents(): array
    {
        return $this->village->livingAgents();
    }

    /**
     * Living souls across the whole world — every settlement summed, tracked and folded alike (a folded
     * cohort contributes its expected headcount). This is the world's population; {@see livingAgents()}
     * is only the settlement in focus. Equal to that focus count when the world holds one settlement.
     */
    public function livingPopulation(): int
    {
        return (int) round(array_sum(array_map(
            static fn (Village $village): float => $village->headcount(),
            $this->villages,
        )));
    }

    public function hasOpenProject(): bool
    {
        return $this->village->hasOpenProject();
    }

    /**
     * A recurring annual festival is routine, not a notable event — so it is
     * recorded in the chronicle only the first time the tradition is observed.
     */
    private function logFestivalOnce(TharadiDate $date, string $festival): void
    {
        if ($festival === $this->lastFestivalKey) {
            return;
        }
        $this->lastFestivalKey = $festival;
        $this->chronicle->record($this->tick, sprintf(
            '%d %s, Year %d — the village first observes the %s.',
            $date->dayOfMonth, $date->monthName, $date->year, $festival,
        ), 'festival');
    }
}
