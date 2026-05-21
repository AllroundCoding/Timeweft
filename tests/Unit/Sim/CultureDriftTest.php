<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Culture\CultureEngine;
use App\Sim\Economy\Stockpile;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;
use App\Sim\World\Agent;
use App\Sim\World\RegionProfile;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class CultureDriftTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    private function adult(int $id): Agent
    {
        return new Agent($id, "A{$id}", 'Vulpini', 'Tharados', 'f', -20 * self::TICKS_PER_YEAR, ['agility' => 50.0], []);
    }

    private static function newYear(): TharadiDate
    {
        return TharadiCalendar::fromTick(0); // Naralis 1
    }

    public function test_drift_moves_a_fraction_of_the_way_toward_a_target(): void
    {
        $from = new Culture('From', collectivism: 80, hierarchy: 80, tradition: 80, longTermOrientation: 80, restraint: 80, achievement: 80, piety: 80);
        $to = new Culture('To', collectivism: 40, hierarchy: 40, tradition: 40, longTermOrientation: 40, restraint: 40, achievement: 40, piety: 40);

        $drifted = $from->driftedToward($to, 0.25);

        // 80 + (40 - 80) * 0.25 = 70
        $this->assertEqualsWithDelta(70.0, $drifted->collectivism, 1e-9);
        $this->assertEqualsWithDelta(70.0, $drifted->piety, 1e-9);
    }

    /** @param list<Agent> $agents */
    private function worldWith(float $food, float $landYield, array $agents): World
    {
        $world = new World(new Rng('drift'));
        $world->region = RegionProfile::tharados();
        $world->village = new Village('Driftholm', 'Tharados', $agents, landYield: $landYield, culture: Culture::tharados());
        $world->village->stockpile = new Stockpile(['food' => $food]);

        return $world;
    }

    public function test_prosperity_drifts_culture_toward_self_expression(): void
    {
        // Flush granary, few mouths well under capacity → secure → values loosen (collectivism falls).
        $world = $this->worldWith(1000.0, 40.0, [$this->adult(1), $this->adult(2), $this->adult(3)]);
        $before = $world->village->culture->collectivism;

        CultureEngine::runDay($world, 0, self::newYear());

        $this->assertLessThan($before, $world->village->culture->collectivism);
        $this->assertEqualsWithDelta($world->village->culture->baselineCohesion(), $world->village->baselineCohesion, 1e-9);
    }

    public function test_scarcity_drifts_culture_toward_survival(): void
    {
        // Empty granary and population over a meagre carrying capacity → precarious → values tighten.
        $agents = array_map(fn (int $i): Agent => $this->adult($i), range(1, 12));
        $world = $this->worldWith(0.0, 10.0, $agents);
        $before = $world->village->culture->collectivism;

        CultureEngine::runDay($world, 0, self::newYear());

        $this->assertGreaterThan($before, $world->village->culture->collectivism);
    }
}
