<?php

namespace Tests\Unit\Sim;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\HealthEngine;
use App\Sim\World\Need;
use App\Sim\World\RegionProfile;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class HealthEngineTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    private function agent(int $id, int $ageYears): Agent
    {
        return new Agent($id, "A{$id}", 'Vulpini', 'Tharados', 'f', -$ageYears * self::TICKS_PER_YEAR, ['agility' => 50.0], [
            'sickness' => new Need('sickness', 0.0, 0.0),
        ]);
    }

    private function world(int $landYield, float $starvation, Agent ...$agents): World
    {
        $world = new World(new Rng('health'));
        $world->region = RegionProfile::tharados();
        $world->village = new Village('Wellholm', 'Tharados', $agents, landYield: (float) $landYield);
        $world->village->starvationFactor = $starvation;

        return $world;
    }

    public function test_a_healthy_young_settlement_stays_well(): void
    {
        // Young adults, room under capacity, fed: recovery outpaces background exposure → no sickness.
        $world = $this->world(40, 1.0, $this->agent(1, 25), $this->agent(2, 30));
        $agent = $world->livingAgents()[0];

        for ($day = 0; $day < 30; $day++) {
            HealthEngine::runDay($world, $day * TharadiCalendar::HOURS_PER_DAY);
        }

        $this->assertSame(0.0, $agent->needs['sickness']->value);
    }

    public function test_famine_makes_people_sick(): void
    {
        $world = $this->world(40, 4.0, $this->agent(1, 25)); // starvationFactor 4 = deep famine
        $agent = $world->livingAgents()[0];

        for ($day = 0; $day < 30; $day++) {
            HealthEngine::runDay($world, $day * TharadiCalendar::HOURS_PER_DAY);
        }

        $this->assertGreaterThan(0.0, $agent->needs['sickness']->value);
    }

    public function test_the_old_grow_frailer_than_the_young(): void
    {
        $world = $this->world(40, 1.0, $this->agent(1, 25), $this->agent(2, 70));
        [$young, $old] = $world->livingAgents();

        for ($day = 0; $day < 60; $day++) {
            HealthEngine::runDay($world, $day * TharadiCalendar::HOURS_PER_DAY);
        }

        $this->assertGreaterThan($young->needs['sickness']->value, $old->needs['sickness']->value);
    }
}
