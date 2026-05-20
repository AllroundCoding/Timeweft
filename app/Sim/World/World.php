<?php

namespace App\Sim\World;

use App\Sim\Behavior\BehaviorEngine;
use App\Sim\Behavior\FestivalCalendar;
use App\Sim\Chronicle\Chronicle;
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
    private int $nextId = 1;
    private ?string $lastFestivalKey = null;

    public function __construct(public readonly Rng $rng)
    {
        $this->chronicle = new Chronicle();
    }

    public static function seedTharadosVillage(Rng $rng, int $population = 5): self
    {
        $world = new self($rng);
        $species = Species::vulpini();
        $region = RegionProfile::tharados();
        $names = new TharadiNameGenerator($rng);
        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        $agents = [];
        for ($i = 0; $i < $population; $i++) {
            $birthTick = -$rng->int(18, 50) * $ticksPerYear;
            $agents[] = $species->birth($world->nextId++, $birthTick, $region, $rng, $names);
        }

        $world->village = new Village('Sunwell Oasis', $region->name, $agents);

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

            foreach ($this->village->agents as $agent) {
                $activity = BehaviorEngine::derive($agent, $date, $festival !== null);
                $agent->activity = $activity;
                BehaviorEngine::applyEffects($agent, $activity, $seasonMultiplier);
            }
        }
    }

    private function logFestivalOnce(TharadiDate $date, string $festival): void
    {
        $key = "{$date->year}:{$date->monthIndex}:{$date->dayOfMonth}:{$festival}";
        if ($key === $this->lastFestivalKey) {
            return;
        }
        $this->lastFestivalKey = $key;
        $this->chronicle->record($this->tick, sprintf(
            '%d %s, Year %d — the village holds the %s.',
            $date->dayOfMonth, $date->monthName, $date->year, $festival,
        ));
    }
}
