<?php

namespace Tests\Feature\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Economy\GoodRegistry;
use App\Sim\Economy\Stockpile;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\MigrationEngine;
use App\Sim\World\RelationsEngine;
use App\Sim\World\TradeEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-125: settlements hold relations that drift with kinship and competition, and that standing gates
 * the rest — enemies refuse to trade, and migrants won't flee into a hostile settlement.
 */
class RelationsTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_kindred_distant_settlements_grow_amicable(): void
    {
        $world = new World(new Rng('rel'));
        $a = $this->village('Kin-A', Culture::tharados(), 0.0, 0.0, pop: 2, capacity: 40);   // unstressed
        $b = $this->village('Kin-B', Culture::tharados(), 400.0, 0.0, pop: 2, capacity: 40); // far, unstressed
        $world->villages = [$a, $b];

        $this->driftYears($world, 15);

        $this->assertGreaterThan(0.6, RelationsEngine::standing($world, $a, $b), 'shared culture, no rivalry → amity grows');
    }

    public function test_close_competing_settlements_fall_to_enmity(): void
    {
        $world = new World(new Rng('rel'));
        // Same culture, but cheek-by-jowl and both desperately overcrowded — they compete.
        $a = $this->village('Rival-A', Culture::tharados(), 0.0, 0.0, pop: 30, capacity: 3);
        $b = $this->village('Rival-B', Culture::tharados(), 10.0, 0.0, pop: 30, capacity: 3);
        $world->villages = [$a, $b];

        $this->driftYears($world, 20);

        $this->assertTrue(RelationsEngine::hostile($world, $a, $b), 'proximate, shared scarcity → enmity');
        $enmity = array_filter($world->chronicle->all(), static fn ($e): bool => $e->type === 'relations-enmity');
        $this->assertNotEmpty($enmity, 'the falling-out is chronicled');
    }

    public function test_enemies_do_not_trade(): void
    {
        foreach ([0.1, 0.9] as $standing) {
            $world = new World(new Rng('rel'));
            $world->goods = GoodRegistry::tharados();
            $exporter = $this->village('Breadbasket', Culture::tharados(), 0.0, 0.0, pop: 4, capacity: 40, stocks: ['food' => 200.0]);
            $importer = $this->village('Dusthold', Culture::tharados(), 30.0, 0.0, pop: 10, capacity: 40, stocks: ['food' => 5.0, 'money' => 1000.0]);
            $world->villages = [$exporter, $importer];
            $world->village = $exporter;
            $world->relations[$exporter->name < $importer->name ? "{$exporter->name}↔{$importer->name}" : "{$importer->name}↔{$exporter->name}"] = $standing;

            $tick = 100 * TharadiCalendar::HOURS_PER_DAY + 8;
            TradeEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

            if ($standing < 0.3) {
                $this->assertSame(5.0, $importer->stockpile->amount('food'), 'an enemy is left to starve — no trade crosses');
            } else {
                $this->assertGreaterThan(5.0, $importer->stockpile->amount('food'), 'amicable neighbours trade freely');
            }
        }
    }

    public function test_migrants_shun_a_hostile_haven(): void
    {
        $crowded = $this->village('Crowdholm', Culture::tharados(), 0.0, 0.0, pop: 24, capacity: 2);
        $hostileNear = $this->village('Foehaven', Culture::tharados(), 40.0, 0.0, pop: 2, capacity: 40);
        $friendlyFar = $this->village('Kinhaven', Culture::tharados(), 300.0, 0.0, pop: 2, capacity: 40);

        $world = new World(new Rng('vaeris'));
        $world->villages = [$crowded, $hostileNear, $friendlyFar];
        $world->village = $crowded;
        $world->relations['Crowdholm↔Foehaven'] = 0.05; // sworn enemies

        $tick = (int) self::TICKS_PER_YEAR;
        MigrationEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $this->assertCount(2, $hostileNear->livingAgents(), 'no refuge with an enemy, near though it is');
        $this->assertGreaterThan(2, count($friendlyFar->livingAgents()), 'migrants take the friendly road instead');
    }

    public function test_a_lone_settlement_has_no_relations(): void
    {
        $world = new World(new Rng('rel'));
        $world->villages = [$this->village('Only', Culture::tharados(), 0.0, 0.0, pop: 5, capacity: 20)];

        $tick = (int) self::TICKS_PER_YEAR;
        RelationsEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $this->assertSame([], $world->relations, 'with no neighbours there are no relations to track');
    }

    private function driftYears(World $world, int $years): void
    {
        for ($y = 1; $y <= $years; $y++) {
            $tick = $y * (int) self::TICKS_PER_YEAR;
            RelationsEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));
        }
    }

    /** @param array<string,float> $stocks */
    private function village(string $name, Culture $culture, float $x, float $y, int $pop, int $capacity, array $stocks = []): Village
    {
        $village = new Village($name, 'Tharados', culture: $culture);
        $village->carryingCapacity = $capacity;
        $village->x = $x;
        $village->y = $y;
        $village->stockpile = new Stockpile($stocks);
        $village->agents = array_map(
            fn (int $i): Agent => new Agent($i + (int) (crc32($name) % 1000), "A{$i}", 'Vulpini', 'Tharados', $i % 2 === 0 ? 'f' : 'm', -25 * self::TICKS_PER_YEAR, ['agility' => 50.0], []),
            range(1, $pop),
        );

        return $village;
    }
}
