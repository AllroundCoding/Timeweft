<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;
use App\Sim\World\Agent;
use App\Sim\World\Need;
use App\Sim\World\RegionProfile;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class EconomyEngineTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    private const OASIS_TICK = 0;                                   // Naralis (Oasis)

    private const SANDSTORM_TICK = 60 * TharadiCalendar::HOURS_PER_DAY; // Kalimos (Sandstorm)

    /** @param array<string,Need> $needs */
    private function agent(int $id, int $birthTick, array $needs = []): Agent
    {
        return new Agent($id, "A{$id}", 'Vulpini', 'Tharados', 'f', $birthTick, ['agility' => 50.0], $needs);
    }

    private function worldWith(Agent ...$agents): World
    {
        $world = new World(new Rng('econ'));
        $world->region = RegionProfile::tharados();
        $world->village = new Village('Testhold', 'Tharados', $agents);

        return $world;
    }

    private static function date(int $tick): TharadiDate
    {
        return TharadiCalendar::fromTick($tick);
    }

    public function test_adults_produce_a_surplus_into_the_granary(): void
    {
        $world = $this->worldWith(
            $this->agent(1, -20 * self::TICKS_PER_YEAR),
            $this->agent(2, -20 * self::TICKS_PER_YEAR),
        );

        EconomyEngine::runDay($world, self::OASIS_TICK, self::date(self::OASIS_TICK));

        // 2 adults × 4 produced (well under the ceiling) − 2 mouths × 1 eaten = 6 left.
        $this->assertEqualsWithDelta(6.0, $world->village->stockpile->amount('food'), 1e-9);
        $this->assertEqualsWithDelta(6.0, $world->village->stockpile->amount('water'), 1e-9);
    }

    public function test_carrying_capacity_is_land_yield_over_the_ration(): void
    {
        $this->assertSame(22, EconomyEngine::carryingCapacityFor(22.0));
        $this->assertSame(40, EconomyEngine::carryingCapacityFor(40.0));
        $this->assertGreaterThan(EconomyEngine::carryingCapacityFor(10.0), EconomyEngine::carryingCapacityFor(30.0));
    }

    public function test_village_derives_its_carrying_capacity_from_land_yield(): void
    {
        $village = new Village('Sunwell Oasis', 'Tharados', landYield: 22.0);

        $this->assertSame(22, $village->carryingCapacity);
    }

    public function test_technology_multiplies_carrying_capacity(): void
    {
        // The Netherlands case: little land, but high technology feeds many.
        $this->assertSame(44, EconomyEngine::carryingCapacityFor(22.0, 2.0));
        $this->assertSame(30, EconomyEngine::carryingCapacityFor(10.0, 3.0));

        $highTech = new Village('Polderhold', 'Tharados', landYield: 10.0, technology: 4.0);
        $this->assertSame(40, $highTech->carryingCapacity);
    }

    public function test_the_region_yields_less_in_the_sandstorm(): void
    {
        $region = RegionProfile::tharados();

        $this->assertGreaterThan($region->yieldMultiplier('Sandstorm'), $region->yieldMultiplier('Oasis'));
        // 2 Oasis months × 1.5 + 6 Sandstorm months × 0.5, over 8 months = 0.75.
        $this->assertEqualsWithDelta(0.75, EconomyEngine::averageYieldMultiplier($region), 1e-9);
    }

    public function test_the_land_and_season_cap_the_harvest(): void
    {
        $world = new World(new Rng('cap'));
        $world->region = RegionProfile::tharados();
        $world->village = new Village('Smallhold', 'Tharados', [
            $this->agent(1, -20 * self::TICKS_PER_YEAR),
            $this->agent(2, -20 * self::TICKS_PER_YEAR),
            $this->agent(3, -20 * self::TICKS_PER_YEAR),
        ], landYield: 10.0);

        // Sandstorm ceiling = 10 land × 0.5 season = 5; three adults could make 12 but get 5; eat 3 → 2.
        EconomyEngine::runDay($world, self::SANDSTORM_TICK, self::date(self::SANDSTORM_TICK));

        $this->assertEqualsWithDelta(2.0, $world->village->stockpile->amount('food'), 1e-9);
    }

    public function test_thrifty_agents_save_more_and_feed_the_treasury_less(): void
    {
        $saver = $this->agent(1, -20 * self::TICKS_PER_YEAR, []);
        $saver->traits['thrift'] = 80.0;
        $spender = $this->agent(2, -20 * self::TICKS_PER_YEAR, []);
        $spender->traits['thrift'] = 20.0;

        $world = $this->worldWith($saver, $spender);
        EconomyEngine::runDay($world, self::OASIS_TICK, self::date(self::OASIS_TICK));

        // wage 1.0/day: saver keeps 0.8, spender keeps 0.2.
        $this->assertEqualsWithDelta(0.8, $saver->stockpile->amount('money'), 1e-9);
        $this->assertEqualsWithDelta(0.2, $spender->stockpile->amount('money'), 1e-9);
        $this->assertGreaterThan($spender->stockpile->amount('money'), $saver->stockpile->amount('money'));
        // The spent remainder (0.2 + 0.8) circulates into the communal treasury.
        $this->assertEqualsWithDelta(1.0, $world->village->stockpile->amount('money'), 1e-9);
    }

    public function test_an_empty_granary_raises_the_starvation_factor_and_chronicles_famine(): void
    {
        // Three children (age 0): no producers, the granary stays empty, food per head ≈ 0.
        $world = $this->worldWith(
            $this->agent(1, 0),
            $this->agent(2, 0),
            $this->agent(3, 0),
        );

        EconomyEngine::runDay($world, self::OASIS_TICK, self::date(self::OASIS_TICK));

        $this->assertGreaterThan(1.0, $world->village->starvationFactor);
        $this->assertTrue($world->village->inFamine);
        $famine = array_filter($world->chronicle->all(), static fn (array $e): bool => str_contains($e['text'], 'famine grips'));
        $this->assertNotEmpty($famine);
    }

    public function test_scarcity_drives_hunger_up_when_no_one_can_produce(): void
    {
        $hungerA = new Need('hunger', 0.0, 100.0 / 16.0);
        $hungerB = new Need('hunger', 0.0, 100.0 / 16.0);

        // Two children (age 0): no producers, so the ration goes unmet.
        $world = $this->worldWith(
            $this->agent(1, 0, ['hunger' => $hungerA]),
            $this->agent(2, 0, ['hunger' => $hungerB]),
        );

        EconomyEngine::runDay($world, self::OASIS_TICK, self::date(self::OASIS_TICK));

        $this->assertGreaterThan(0.0, $hungerA->value);
        $this->assertGreaterThan(0.0, $hungerB->value);
        $this->assertSame(0.0, $world->village->stockpile->amount('food'));
    }
}
