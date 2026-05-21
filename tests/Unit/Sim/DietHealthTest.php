<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use App\Sim\Economy\GoodRegistry;
use App\Sim\Economy\RecipeBook;
use App\Sim\Economy\Stockpile;
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

    /** @param array<string,float> $stocks */
    private function larder(array $stocks): World
    {
        $world = new World(new Rng('larder'));
        $world->goods = GoodRegistry::tharados();
        $world->recipes = RecipeBook::tharados();
        $world->village = new Village('Larderhold', 'Tharados', [], landYield: 40.0);
        $world->village->stockpile = new Stockpile($stocks);

        return $world;
    }

    public function test_a_full_larder_feeds_a_better_diet_than_a_bare_one(): void
    {
        // A stocked larder cooks the hearty stew for everyone; a grain-only larder can cook nothing.
        $full = $this->larder(['grain' => 100.0, 'dates' => 100.0, 'goat meat' => 100.0]);
        $bare = $this->larder(['grain' => 100.0]);

        $this->assertEqualsWithDelta(1.0, EconomyEngine::cookedDietQuality($full, 5), 1e-9);
        $this->assertLessThan(
            EconomyEngine::cookedDietQuality($full, 5),
            EconomyEngine::cookedDietQuality($bare, 5),
        );
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
