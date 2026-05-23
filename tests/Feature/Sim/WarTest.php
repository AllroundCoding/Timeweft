<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Economy\Stockpile;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\Village;
use App\Sim\World\WarEngine;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-196: the hostile payoff of the relations layer. Enemies don't merely withhold trade — the
 * stronger raids the weaker's stores, and a deeper enmity flares into open war that costs lives on
 * both sides. Peace, and a lone settlement, see none of it.
 */
class WarTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_the_stronger_raids_the_weaker_enemy(): void
    {
        $raider = $this->village('Raidholm', 10, ['food' => 0.0]);
        $victim = $this->village('Marraka', 4, ['food' => 100.0, 'money' => 100.0]);
        $world = new World(new Rng('war'));
        $world->villages = [$raider, $victim];
        $world->relations['Marraka↔Raidholm'] = 0.2; // hostile, but short of open war

        for ($y = 1; $y <= 8; $y++) {
            $tick = $y * (int) self::TICKS_PER_YEAR;
            WarEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));
        }

        $raids = array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'raid');
        $this->assertNotEmpty($raids, 'a hostile pair eventually raids');
        $this->assertLessThan(100.0, $victim->stockpile->amount('food'), 'the victim is plundered');
        $this->assertGreaterThan(0.0, $raider->stockpile->amount('food'), 'the raider carries off the loot');
    }

    public function test_open_war_claims_lives_on_both_sides(): void
    {
        $a = $this->village('Kharad', 20, []);
        $b = $this->village('Stormhold', 20, []);
        $world = new World(new Rng('war'));
        $world->villages = [$a, $b];
        $world->relations['Kharad↔Stormhold'] = 0.05; // open war

        $tick = (int) self::TICKS_PER_YEAR;
        WarEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $this->assertLessThan(20, count($a->livingAgents()), 'war costs the first side lives');
        $this->assertLessThan(20, count($b->livingAgents()), 'and the second');
        $war = array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'war');
        $this->assertNotEmpty($war, 'the war is chronicled');
    }

    public function test_peace_brings_no_raids_or_war(): void
    {
        $a = $this->village('Kin-A', 10, ['food' => 100.0]);
        $b = $this->village('Kin-B', 4, ['food' => 100.0]);
        $world = new World(new Rng('war'));
        $world->villages = [$a, $b];
        $world->relations['Kin-A↔Kin-B'] = 0.9; // allies

        for ($y = 1; $y <= 8; $y++) {
            $tick = $y * (int) self::TICKS_PER_YEAR;
            WarEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));
        }

        $this->assertSame([], $world->chronicle->all(), 'friends neither raid nor war');
        $this->assertEqualsWithDelta(100.0, $b->stockpile->amount('food'), 1e-9, 'stores untouched');
    }

    public function test_a_lone_settlement_sees_no_war(): void
    {
        $world = new World(new Rng('war'));
        $world->villages = [$this->village('Only', 10, ['food' => 100.0])];

        $tick = (int) self::TICKS_PER_YEAR;
        WarEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $this->assertSame([], $world->chronicle->all());
    }

    /** @param array<string,float> $stocks */
    private function village(string $name, int $pop, array $stocks): Village
    {
        $village = new Village($name, 'Tharados');
        $village->stockpile = new Stockpile($stocks);
        $village->agents = array_map(
            fn (int $i): Agent => new Agent($i + (int) (crc32($name) % 1000), "A{$i}", 'Vulpini', 'Tharados', $i % 2 === 0 ? 'f' : 'm', -25 * self::TICKS_PER_YEAR, ['agility' => 50.0], []),
            range(1, $pop),
        );

        return $village;
    }
}
