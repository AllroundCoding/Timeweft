<?php

namespace App\Sim\World;

use App\Sim\Support\Rng;
use App\Sim\Support\TharadiNameGenerator;
use App\Sim\Time\TharadiCalendar;

/** Top-level sim container: the canonical clock, the world's agents, and the RNG. */
final class World
{
    public int $tick = 0;
    public Village $village;
    private int $nextId = 1;

    public function __construct(public readonly Rng $rng) {}

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
        for ($t = 0; $t < $ticks; $t++) {
            $this->tick++;
            foreach ($this->village->agents as $agent) {
                $agent->advanceNeeds(1);
            }
        }
    }
}
