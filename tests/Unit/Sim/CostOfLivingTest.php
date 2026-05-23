<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-135: cost of living closes the spender/saver half of pricing (TWT-47/23). Households spend money on
 * their keep at the settlement's local food price, drawn from personal savings — so a scarce, expensive
 * settlement accumulates little personal wealth while an abundant, cheap one lets the thrifty build it.
 * An emergent wealth gradient that tracks material conditions, leaving the communal treasury untouched.
 */
class CostOfLivingTest extends TestCase
{
    private const TICK = 5 * 240 * 24;

    public function test_keep_is_dearer_where_food_is_scarce(): void
    {
        $scarce = EconomyEngine::costOfLiving(2.0, 8);     // a quarter-day of food per head
        $abundant = EconomyEngine::costOfLiving(400.0, 8); // fifty days per head

        $this->assertGreaterThan($abundant, $scarce, 'the local food price makes keep dearer where food is short');
    }

    public function test_personal_wealth_accrues_where_food_is_cheap(): void
    {
        $abundant = $this->settlement(landYield: 60.0); // food piles up → cheap → little eats into savings
        $lean = $this->settlement(landYield: 3.0);      // food stays short → dear → savings are eaten

        $this->runEconomy($abundant, 120);
        $this->runEconomy($lean, 120);

        $this->assertGreaterThan(
            $this->meanWealth($lean),
            $this->meanWealth($abundant),
            'personal wealth accrues where living is cheap, and is eaten where it is dear',
        );
    }

    private function settlement(float $landYield): World
    {
        $world = World::seedTharadosVillage(new Rng('col'), 6);
        $world->village = new Village('Holdfast', 'Tharados', $world->village->agents, landYield: $landYield, culture: $world->village->culture);
        $world->village->regionProfile = $world->region;
        $world->villages = [$world->village];

        return $world;
    }

    private function runEconomy(World $world, int $days): void
    {
        $tick = self::TICK;
        for ($day = 0; $day < $days; $day++) {
            $tick += TharadiCalendar::HOURS_PER_DAY;
            EconomyEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));
        }
    }

    private function meanWealth(World $world): float
    {
        $living = $world->village->livingAgents();
        if ($living === []) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($living as $agent) {
            $total += $agent->stockpile->amount('money');
        }

        return $total / count($living);
    }
}
