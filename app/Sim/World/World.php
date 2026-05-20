<?php

namespace App\Sim\World;

use App\Sim\Behavior\BehaviorEngine;
use App\Sim\Behavior\FestivalCalendar;
use App\Sim\Chronicle\Chronicle;
use App\Sim\Direction\Milestone;
use App\Sim\Direction\StoryDirector;
use App\Sim\Projects\Project;
use App\Sim\Projects\ProjectEngine;
use App\Sim\Support\Rng;
use App\Sim\Support\TharadiNameGenerator;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;

/** Top-level sim container: the canonical clock, the world's agents, RNG, and chronicle. */
final class World
{
    public int $tick = 0;
    public Village $village;
    public Chronicle $chronicle;
    public Species $species;
    public RegionProfile $region;
    /** @var list<Milestone> */
    public array $milestones = [];
    public ?Project $activeProject = null;
    private TharadiNameGenerator $names;
    private int $nextId = 1;
    private ?string $lastFestivalKey = null;

    public function __construct(public readonly Rng $rng)
    {
        $this->chronicle = new Chronicle();
    }

    public static function seedTharadosVillage(Rng $rng, int $population = 5): self
    {
        $world = new self($rng);
        $world->species = Species::vulpini();
        $world->region = RegionProfile::tharados();
        $world->names = new TharadiNameGenerator($rng);
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        $agents = [];
        for ($i = 0; $i < $population; $i++) {
            $birthTick = -$rng->int(18, 50) * $ticksPerYear;
            $agents[] = $world->species->birth($world->nextId++, $birthTick, $world->region, $rng, $world->names);
        }

        $world->village = new Village('Sunwell Oasis', $world->region->name, $agents, carryingCapacity: 22);
        $world->milestones[] = new Milestone(
            name: 'trading post on the caravan road',
            deadlineYear: 12,
            prereqPopulation: 12,
        );

        return $world;
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

            foreach ($this->livingAgents() as $agent) {
                $activity = BehaviorEngine::derive($agent, $date, $festival !== null);
                $agent->activity = $activity;
                BehaviorEngine::applyEffects($agent, $activity, $seasonMultiplier);
            }

            // Emergence, projects, and story-direction run once per in-world day.
            if ($date->hour === 8) {
                EmergenceEngine::runDay($this, $this->tick, $date);
                ProjectEngine::runDay($this, $this->tick, $date);
                foreach ($this->milestones as $milestone) {
                    StoryDirector::evaluate($this, $milestone, $this->tick, $date, $this->rng);
                }
            }
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
        ));

        return $child;
    }

    /** @return list<Agent> */
    public function livingAgents(): array
    {
        return array_values(array_filter($this->village->agents, fn (Agent $a) => $a->alive));
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
        ));
    }
}
