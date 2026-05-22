<?php

namespace Tests\Unit\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Economy\GoodRegistry;
use App\Sim\Economy\Pricing;
use App\Sim\Economy\Stockpile;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\TradeEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-45: inter-settlement trade. A settlement with a staple surplus ships it to one that is short,
 * so the short settlement is fed by more than its own land could grow — its effective carrying
 * capacity rises above what it produces alone.
 */
class TradeEngineTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_surplus_settlement_feeds_a_short_one_beyond_its_own_land(): void
    {
        $world = $this->world();
        $rich = $world->villages[0];  // 4 souls, a brimming granary
        $poor = $world->villages[1];  // 10 souls, all but bare

        // A midyear day, so the transfer happens but no yearly chronicle line is drawn.
        $tick = 100 * TharadiCalendar::HOURS_PER_DAY + 8;
        TradeEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        // The short settlement is now fed to the import threshold (5 days/head) — sustenance its own
        // land never produced. The surplus settlement parted with exactly that food.
        $this->assertEqualsWithDelta(50.0, $poor->stockpile->amount('food'), 1e-9, 'the short settlement is fed beyond its land');
        $this->assertEqualsWithDelta(155.0, $rich->stockpile->amount('food'), 1e-9, 'the surplus settlement shipped its excess');

        // Payment flowed with the goods (food valued at 1, water at 5): money moved buyer -> seller.
        $this->assertLessThan(500.0, $poor->stockpile->amount('money'), 'the buyer paid for its imports');
        $this->assertGreaterThan(0.0, $rich->stockpile->amount('money'), 'the seller was paid');
    }

    public function test_trade_closes_the_price_gap_self_regulating(): void
    {
        $world = $this->world();
        $poor = $world->villages[1];

        // The short settlement's food is dear before relief arrives...
        $before = Pricing::localPrice(1.0, $poor->stockpile->amount('food'), count($poor->livingAgents()));

        $tick = 100 * TharadiCalendar::HOURS_PER_DAY + 8;
        TradeEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        // ...and cheaper once imports have filled the granary — the price signal regulates itself.
        $after = Pricing::localPrice(1.0, $poor->stockpile->amount('food'), count($poor->livingAgents()));
        $this->assertLessThan($before, $after, 'imports ease scarcity, so the local price falls');
    }

    public function test_a_lone_settlement_never_trades(): void
    {
        $world = new World(new Rng('trade'));
        $world->goods = GoodRegistry::tharados();
        $only = new Village('Sunwell Oasis', 'Tharados');
        $only->agents = $this->adults(5);
        $only->stockpile = new Stockpile(['food' => 200.0]);
        $world->villages = [$only];
        $world->village = $only;

        $tick = (int) self::TICKS_PER_YEAR; // a turn-of-year tick, where a route would otherwise be logged
        TradeEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $this->assertSame(200.0, $only->stockpile->amount('food'), 'with nowhere to trade, stores are untouched');
        $this->assertEmpty($world->chronicle->all(), 'and nothing is chronicled (the lone run draws no trade)');
    }

    public function test_an_active_route_is_chronicled_at_the_turn_of_the_year(): void
    {
        $world = $this->world();

        $tick = (int) self::TICKS_PER_YEAR; // 1 Naralis — the new year
        TradeEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $trades = array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'trade');
        $this->assertNotEmpty($trades, 'a route active at the turn of the year is recorded');
    }

    private function world(): World
    {
        $world = new World(new Rng('trade'));
        $world->goods = GoodRegistry::tharados();

        $rich = new Village('Breadbasket', 'Tharados');
        $rich->agents = $this->adults(4);
        $rich->stockpile = new Stockpile(['food' => 200.0, 'water' => 200.0, 'money' => 0.0]);

        $poor = new Village('Dusthold', 'Tharados');
        $poor->agents = $this->adults(10);
        $poor->stockpile = new Stockpile(['food' => 5.0, 'water' => 5.0, 'money' => 500.0]);

        $world->villages = [$rich, $poor];
        $world->village = $rich;

        return $world;
    }

    /** @return list<Agent> */
    private function adults(int $count): array
    {
        return array_map(
            fn (int $i): Agent => new Agent(
                $i, "A{$i}", 'Vulpini', 'Tharados', $i % 2 === 0 ? 'f' : 'm', -25 * self::TICKS_PER_YEAR, ['agility' => 50.0], [],
            ),
            range(1, $count),
        );
    }
}
