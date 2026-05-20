<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\Need;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class EconomyEngineTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    /** @param array<string,Need> $needs */
    private function agent(int $id, int $birthTick, array $needs = []): Agent
    {
        return new Agent($id, "A{$id}", 'Vulpini', 'Tharados', 'f', $birthTick, ['agility' => 50.0], $needs);
    }

    private function worldWith(Agent ...$agents): World
    {
        $world = new World(new Rng('econ'));
        $world->village = new Village('Testhold', 'Tharados', $agents);

        return $world;
    }

    public function test_adults_produce_a_surplus_into_the_granary(): void
    {
        $world = $this->worldWith(
            $this->agent(1, -20 * self::TICKS_PER_YEAR),
            $this->agent(2, -20 * self::TICKS_PER_YEAR),
        );

        EconomyEngine::runDay($world, 0);

        // 2 adults × 4 produced − 2 mouths × 1 eaten = 6 left.
        $this->assertEqualsWithDelta(6.0, $world->village->stockpile->amount('food'), 1e-9);
        $this->assertEqualsWithDelta(6.0, $world->village->stockpile->amount('water'), 1e-9);
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

        EconomyEngine::runDay($world, 0);

        $this->assertGreaterThan(0.0, $hungerA->value);
        $this->assertGreaterThan(0.0, $hungerB->value);
        $this->assertSame(0.0, $world->village->stockpile->amount('food'));
    }
}
