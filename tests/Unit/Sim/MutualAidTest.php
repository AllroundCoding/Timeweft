<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\EmergenceEngine;
use App\Sim\World\Need;
use App\Sim\World\RegionProfile;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class MutualAidTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_mutual_aid_buffers_the_famine_die_back(): void
    {
        // Same famine (starvation factor 4): a sharing settlement loses less of it to death.
        $stingy = EmergenceEngine::starvationWithAid(4.0, 0.0);
        $generous = EmergenceEngine::starvationWithAid(4.0, 1.0);

        $this->assertEqualsWithDelta(4.0, $stingy, 1e-9);   // no sharing → the full die-back
        $this->assertLessThan($stingy, $generous);          // sharing softens it
        $this->assertGreaterThan(1.0, $generous);           // but never erases the famine
    }

    public function test_aid_does_nothing_when_there_is_no_famine(): void
    {
        $this->assertEqualsWithDelta(1.0, EmergenceEngine::starvationWithAid(1.0, 0.8), 1e-9);
    }

    public function test_a_generous_settlement_has_more_mutual_aid_than_a_stingy_one(): void
    {
        $generous = $this->worldOf(generosity: 90.0);
        $stingy = $this->worldOf(generosity: 20.0);

        $this->assertGreaterThan(
            EconomyEngine::mutualAid($stingy, 0),
            EconomyEngine::mutualAid($generous, 0),
        );
    }

    private function worldOf(float $generosity): World
    {
        $agents = array_map(
            fn (int $i): Agent => new Agent($i, "A{$i}", 'Vulpini', 'Tharados', 'f', -20 * self::TICKS_PER_YEAR, ['generosity' => $generosity, 'conscientiousness' => 50.0], ['sickness' => new Need('sickness', 0.0, 0.0)]),
            range(1, 4),
        );
        $world = new World(new Rng('aid'));
        $world->region = RegionProfile::tharados();
        $world->village = new Village('Aidholm', 'Tharados', $agents, landYield: 40.0);

        return $world;
    }
}
