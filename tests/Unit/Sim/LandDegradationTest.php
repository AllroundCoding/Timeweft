<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\Need;
use App\Sim\World\RegionProfile;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class LandDegradationTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_overshoot_exhausts_the_land(): void
    {
        // Population past carrying capacity over-works the land, so its yield erodes.
        $this->assertLessThan(0.0, EconomyEngine::landYieldChange(20.0, 20.0, 1.5));
    }

    public function test_fallow_land_heals_back_toward_its_pristine_yield(): void
    {
        // Worked below K, a degraded land recovers — but the gain is bounded by its base.
        $this->assertGreaterThan(0.0, EconomyEngine::landYieldChange(10.0, 20.0, 0.5));
        $this->assertEqualsWithDelta(0.0, EconomyEngine::landYieldChange(20.0, 20.0, 0.5), 1e-9);
    }

    public function test_a_settlement_that_overshoots_loses_yield(): void
    {
        $world = $this->worldOf(landYield: 4.0, agents: 12);
        $this->assertSame(4.0, $world->village->baseLandYield);

        EconomyEngine::degradeLand($world);

        $this->assertLessThan(4.0, $world->village->landYield);
    }

    public function test_degradation_bottoms_out_at_the_floor(): void
    {
        $world = $this->worldOf(landYield: 4.0, agents: 30);

        for ($y = 0; $y < 200; $y++) {
            EconomyEngine::degradeLand($world);
        }

        $this->assertGreaterThanOrEqual(0.3 * 4.0 - 1e-9, $world->village->landYield);
    }

    public function test_a_sparse_settlement_recovers_degraded_land(): void
    {
        $world = $this->worldOf(landYield: 4.0, agents: 1);
        $world->village->landYield = 2.0; // pretend a past overshoot scarred it

        EconomyEngine::degradeLand($world);

        $this->assertGreaterThan(2.0, $world->village->landYield);
        $this->assertLessThanOrEqual(4.0, $world->village->landYield);
    }

    private function worldOf(float $landYield, int $agents): World
    {
        $roster = array_map(
            fn (int $i): Agent => new Agent($i, "A{$i}", 'Vulpini', 'Tharados', $i % 2 === 0 ? 'f' : 'm', -25 * self::TICKS_PER_YEAR, ['openness' => 50.0], ['sickness' => new Need('sickness', 0.0, 0.0)]),
            range(1, $agents),
        );
        $world = new World(new Rng('land'));
        $world->region = RegionProfile::tharados();
        $world->village = new Village('Dustholm', 'Tharados', $roster, landYield: $landYield);

        return $world;
    }
}
