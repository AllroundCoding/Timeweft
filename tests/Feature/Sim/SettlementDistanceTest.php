<?php

namespace Tests\Feature\Sim;

use App\Sim\Economy\GoodRegistry;
use App\Sim\Economy\Stockpile;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\MigrationEngine;
use App\Sim\World\TradeEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-127: settlements have map positions, and distance is a first-class factor. Trade loses more in
 * transit the farther it travels — but an established route loses less over time, so it effectively
 * reaches farther the longer it runs (distance × time). Migration favours nearer havens.
 */
class SettlementDistanceTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_distance_is_the_straight_line_between_settlements(): void
    {
        $a = new Village('A', 'Tharados');
        $a->x = 0.0;
        $a->y = 0.0;
        $b = new Village('B', 'Tharados');
        $b->x = 3.0;
        $b->y = 4.0;

        $this->assertEqualsWithDelta(5.0, $a->distanceTo($b), 1e-9);
        $this->assertEqualsWithDelta(5.0, $b->distanceTo($a), 1e-9, 'distance is symmetric');
    }

    public function test_a_nearer_settlement_receives_more_of_a_shipment(): void
    {
        $world = new World(new Rng('dist'));
        $world->goods = GoodRegistry::tharados();

        $exporter = $this->village('Breadbasket', 4, ['food' => 200.0, 'water' => 0.0, 'money' => 0.0], 0.0, 0.0);
        $near = $this->village('Nearhold', 10, ['food' => 5.0, 'money' => 1000.0], 50.0, 0.0);
        $far = $this->village('Farhold', 10, ['food' => 5.0, 'money' => 1000.0], 400.0, 0.0);
        $world->villages = [$exporter, $near, $far];
        $world->village = $exporter;

        $tick = 100 * TharadiCalendar::HOURS_PER_DAY + 8; // a midyear day
        TradeEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        // Both are fed, but the close settlement keeps more of what was sent — distance taxes the haul.
        $this->assertGreaterThan(5.0, $far->stockpile->amount('food'), 'the far settlement still gets some relief');
        $this->assertGreaterThan($far->stockpile->amount('food'), $near->stockpile->amount('food'), 'the near one keeps more');
    }

    public function test_a_matured_route_delivers_more_than_a_fresh_one_over_the_same_distance(): void
    {
        $received = function (bool $matured): float {
            $world = new World(new Rng('mature'));
            $world->goods = GoodRegistry::tharados();
            $exporter = $this->village('Breadbasket', 4, ['food' => 200.0], 0.0, 0.0);
            $importer = $this->village('Farhold', 10, ['food' => 5.0, 'money' => 1000.0], 400.0, 0.0);
            $world->villages = [$exporter, $importer];
            $world->village = $exporter;
            if ($matured) {
                $world->routes['Breadbasket↔Farhold'] = ['ageYears' => 10, 'lastYear' => PHP_INT_MIN];
            }

            $tick = 100 * TharadiCalendar::HOURS_PER_DAY + 8;
            TradeEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

            return $importer->stockpile->amount('food');
        };

        // Time makes distance cheaper: the worn, trusted route loses less than the fresh one.
        $this->assertGreaterThan($received(false), $received(true), 'a mature route reaches farther');
    }

    public function test_a_trading_route_matures_with_use(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 16);
        $world->foundVillage('Breadbasket', 40, landYield: 60.0);
        $world->foundVillage('Dusthold', 20, landYield: 10.0);

        $world->advance(self::TICKS_PER_YEAR * 20);

        $matured = array_filter($world->routes, static fn (array $r): bool => $r['ageYears'] >= 2);
        $this->assertNotEmpty($matured, 'a route used across several years accrues maturity');
    }

    public function test_migrants_favour_the_nearer_haven(): void
    {
        $crowded = $this->village('Crowdholm', 24, [], 0.0, 0.0);
        $crowded->carryingCapacity = 2; // far over its ceiling — strong push
        $near = $this->village('Nearhaven', 2, [], 60.0, 0.0);
        $near->carryingCapacity = 40;
        $far = $this->village('Farhaven', 2, [], 500.0, 0.0);
        $far->carryingCapacity = 40;

        $world = new World(new Rng('vaeris'));
        $world->villages = [$crowded, $near, $far];
        $world->village = $crowded;

        $tick = (int) self::TICKS_PER_YEAR; // 1 Naralis — the turn of the year
        MigrationEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        // Both havens have equal room, but the close one drew the migrants.
        $this->assertGreaterThan(2, count($near->livingAgents()), 'the near haven gained migrants');
        $this->assertCount(2, $far->livingAgents(), 'the far haven gained none');
    }

    /**
     * @param  array<string,float>  $stocks
     */
    private function village(string $name, int $adults, array $stocks, float $x, float $y): Village
    {
        $village = new Village($name, 'Tharados');
        $village->agents = array_map(
            fn (int $i): Agent => new Agent($i + crc32($name) % 1000, "A{$i}", 'Vulpini', 'Tharados', $i % 2 === 0 ? 'f' : 'm', -25 * self::TICKS_PER_YEAR, ['agility' => 50.0], []),
            range(1, $adults),
        );
        $village->stockpile = new Stockpile($stocks);
        $village->x = $x;
        $village->y = $y;

        return $village;
    }
}
