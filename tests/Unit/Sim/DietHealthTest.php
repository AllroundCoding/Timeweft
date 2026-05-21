<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use App\Sim\Economy\GoodRegistry;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\HealthEngine;
use App\Sim\World\Need;
use App\Sim\World\RegionProfile;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class DietHealthTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_lean_season_narrows_the_diet(): void
    {
        $goods = GoodRegistry::tharados();

        // Oasis keeps everything (threshold 150); the Sandstorm (threshold 50) spoils the meat.
        $oasis = EconomyEngine::dietQualityFor($goods, 150.0);
        $sandstorm = EconomyEngine::dietQualityFor($goods, 50.0);

        $this->assertEqualsWithDelta(1.0, $oasis, 1e-9);
        $this->assertLessThan($oasis, $sandstorm);
        $this->assertGreaterThan(0.0, $sandstorm);
    }

    public function test_a_poor_diet_slows_recovery_so_the_frail_sicken(): void
    {
        $elder = fn (): Agent => new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', -70 * self::TICKS_PER_YEAR, ['agility' => 50.0], [
            'sickness' => new Need('sickness', 0.0, 0.0),
        ]);

        $wellFed = $this->worldWith($elder(), dietQuality: 1.0);
        $poorlyFed = $this->worldWith($elder(), dietQuality: 0.4);

        for ($day = 0; $day < 60; $day++) {
            HealthEngine::runDay($wellFed, $day * TharadiCalendar::HOURS_PER_DAY);
            HealthEngine::runDay($poorlyFed, $day * TharadiCalendar::HOURS_PER_DAY);
        }

        $this->assertGreaterThan(
            $wellFed->livingAgents()[0]->needs['sickness']->value,
            $poorlyFed->livingAgents()[0]->needs['sickness']->value,
        );
    }

    private function worldWith(Agent $agent, float $dietQuality): World
    {
        $world = new World(new Rng('diet'));
        $world->region = RegionProfile::tharados();
        $world->village = new Village('Dietholm', 'Tharados', [$agent], landYield: 40.0);
        $world->village->dietQuality = $dietQuality;

        return $world;
    }
}
