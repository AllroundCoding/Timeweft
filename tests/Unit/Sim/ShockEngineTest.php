<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\Stockpile;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;
use App\Sim\World\Agent;
use App\Sim\World\Need;
use App\Sim\World\ShockEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class ShockEngineTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    private function adult(int $id): Agent
    {
        return new Agent($id, "A{$id}", 'Vulpini', 'Tharados', 'f', -20 * self::TICKS_PER_YEAR, ['agility' => 50.0], []);
    }

    private static function date(): TharadiDate
    {
        return TharadiCalendar::fromTick(0);
    }

    public function test_a_raid_claims_lives(): void
    {
        $world = new World(new Rng('raid'));
        $world->village = new Village('Targethold', 'Tharados', [
            $this->adult(1), $this->adult(2), $this->adult(3), $this->adult(4),
            $this->adult(5), $this->adult(6), $this->adult(7), $this->adult(8),
        ]);

        $before = count($world->livingAgents());
        ShockEngine::applyRaid($world, 0, self::date(), $world->rng);

        $this->assertLessThan($before, count($world->livingAgents()));
        $raid = array_filter($world->chronicle->all(), static fn (array $e): bool => str_contains($e['text'], 'raiders strike'));
        $this->assertNotEmpty($raid);
    }

    public function test_a_plague_makes_everyone_sick(): void
    {
        $world = new World(new Rng('plague'));
        $world->village = new Village('Plaguehold', 'Tharados', [
            new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', -20 * self::TICKS_PER_YEAR, ['agility' => 50.0], ['sickness' => new Need('sickness', 0.0, 0.0)]),
            new Agent(2, 'B', 'Vulpini', 'Tharados', 'm', -30 * self::TICKS_PER_YEAR, ['agility' => 50.0], ['sickness' => new Need('sickness', 0.0, 0.0)]),
        ]);

        ShockEngine::applyPlague($world, 0, self::date());

        foreach ($world->livingAgents() as $agent) {
            $this->assertGreaterThan(0.0, $agent->needs['sickness']->value);
        }
        $plague = array_filter($world->chronicle->all(), static fn (array $e): bool => str_contains($e['text'], 'plague'));
        $this->assertNotEmpty($plague);
    }

    public function test_a_famine_ruins_the_stores(): void
    {
        $world = new World(new Rng('blight'));
        $world->village = new Village('Larderhold', 'Tharados', [$this->adult(1)]);
        $world->village->stockpile = new Stockpile(['food' => 1000.0, 'water' => 1000.0]);

        ShockEngine::applyFamine($world, 0, self::date());

        $this->assertLessThan(1000.0, $world->village->stockpile->amount('food'));
        $this->assertLessThan(1000.0, $world->village->stockpile->amount('water'));
        $blight = array_filter($world->chronicle->all(), static fn (array $e): bool => str_contains($e['text'], 'blight'));
        $this->assertNotEmpty($blight);
    }
}
