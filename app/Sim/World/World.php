<?php

namespace App\Sim\World;

use App\Sim\Behavior\BehaviorEngine;
use App\Sim\Behavior\FestivalCalendar;
use App\Sim\Causality\Intervention;
use App\Sim\Chronicle\Chronicle;
use App\Sim\Culture\Culture;
use App\Sim\Culture\CultureEngine;
use App\Sim\Direction\Milestone;
use App\Sim\Direction\StoryDirector;
use App\Sim\Economy\EconomyEngine;
use App\Sim\Economy\GoodRegistry;
use App\Sim\Economy\RecipeBook;
use App\Sim\Institutions\InstitutionEngine;
use App\Sim\Projects\ProjectEngine;
use App\Sim\Support\Rng;
use App\Sim\Support\TharadiNameGenerator;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;

/** Top-level sim container: the canonical clock, the world's agents, RNG, and chronicle. */
final class World
{
    private const ADULT_AGE = 16;

    public int $tick = 0;

    /** The settlement currently in focus — the engines operate on it; reset to the primary after a run. */
    public Village $village;

    /** @var list<Village> every settlement in the world (the village above is whichever is being simulated) */
    public array $villages = [];

    public Chronicle $chronicle;

    public Species $species;

    public RegionProfile $region;

    /** The goods this world knows — each a name → stat vector (nutrition, value, perishability). */
    public GoodRegistry $goods;

    /** The recipes the settlement can cook from its goods. */
    public RecipeBook $recipes;

    /** @var list<Milestone> */
    public array $milestones = [];

    /** An optional retroactive edit replayed into this run (suppresses a recorded shock); null = the true history. */
    public ?Intervention $intervention = null;

    private TharadiNameGenerator $names;

    private int $nextId = 1;

    private ?string $lastFestivalKey = null;

    public function __construct(public readonly Rng $rng)
    {
        $this->chronicle = new Chronicle;
    }

    public static function seedTharadosVillage(Rng $rng, int $population = 5): self
    {
        $world = new self($rng);
        $world->species = Species::vulpini();
        $world->region = RegionProfile::tharados();
        $world->goods = GoodRegistry::tharados();
        $world->recipes = RecipeBook::tharados();
        $world->names = new TharadiNameGenerator($rng);
        // Culture is generated from the region's materials first, so it can shape the founders it births.
        $culture = Culture::fromMaterialConditions('Tharadi', $world->region->scarcity(), $world->region->seasonalVolatility(), $world->region->landTenureConcentration());
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        $agents = [];
        for ($i = 0; $i < $population; $i++) {
            $birthTick = -$rng->int(18, 50) * $ticksPerYear;
            $agents[] = $world->species->birth($world->nextId++, $birthTick, $world->region, $culture, $rng, $world->names);
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
     * Found a further settlement in this world — fresh founders run by the same engine alongside the
     * others. The seam multi-settlement trade and migration build on. Given a region archetype, the
     * settlement is a different biome: its founders adapt to that land, its culture is generated from
     * that land's materials, and it grows a different basket — so it specializes apart from its
     * neighbours. Without one it defaults to the world's region (the canonical run is unchanged).
     */
    public function foundVillage(string $name, int $population, float $landYield = 22.0, ?RegionArchetype $archetype = null): Village
    {
        $region = $archetype?->toRegionProfile() ?? $this->region;
        $cultureName = $archetype?->cultureName ?? 'Tharadi';
        $culture = Culture::fromMaterialConditions($cultureName, $region->scarcity(), $region->seasonalVolatility(), $region->landTenureConcentration());
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        $agents = [];
        for ($i = 0; $i < $population; $i++) {
            $birthTick = -$this->rng->int(18, 50) * $ticksPerYear;
            $agents[] = $this->species->birth($this->nextId++, $birthTick, $region, $culture, $this->rng, $this->names);
        }

        $village = new Village($name, $region->name, $agents, landYield: $landYield, culture: $culture);
        $village->regionProfile = $region;
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

            // Each settlement is simulated in turn; the engines read whichever is currently in focus.
            foreach ($this->villages as $village) {
                $this->village = $village;
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
                    EmergenceEngine::runDay($this, $this->tick, $date);
                    ProjectEngine::runDay($this, $this->tick, $date);
                    InstitutionEngine::runDay($this, $this->tick, $date);
                    ShockEngine::runDay($this, $this->tick, $date);
                }
            }

            // World-level steps — story direction and cross-settlement migration — once a day.
            if ($date->hour === 8) {
                foreach ($this->milestones as $milestone) {
                    // Each beat steers from its own sub-stream, so authoring an arc never reshuffles
                    // the emergent world (the perturbation TWT-39 ran into).
                    StoryDirector::evaluate($this, $milestone, $this->tick, $date, $this->rng->stream('director', $milestone->name, $this->tick));
                }
                TradeEngine::runDay($this, $this->tick, $date);
                MigrationEngine::runDay($this, $this->tick, $date);
            }
        }

        // Leave the cursor on the primary settlement for inspection (reports, queries).
        if ($this->villages !== []) {
            $this->village = $this->villages[0];
        }
    }

    public function spawnChild(Agent $mother, Agent $father, int $birthTick, TharadiDate $date): Agent
    {
        $child = $this->species->breed($this->nextId++, $mother, $father, $birthTick, $this->rng, $this->names);
        $this->village->agents[] = $child;
        $mother->lastBirthTick = $birthTick;

        $this->chronicle->record($birthTick, sprintf(
            '%d %s, Year %d — %s is born to %s and %s.',
            $date->dayOfMonth, $date->monthName, $date->year, $child->name, $mother->name, $father->name,
        ), 'birth', [$child->id, $mother->id, $father->id], array_values(array_filter([$mother->pairingEventId])));

        return $child;
    }

    /** @return list<Agent> the living members of the settlement currently in focus */
    public function livingAgents(): array
    {
        return $this->village->livingAgents();
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
