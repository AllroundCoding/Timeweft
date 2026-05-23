<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\ProfessionEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\CaravanEngine;
use App\Sim\World\TradeEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-99: agent-driven trade (design doc 16) — the bottom-up complement to bulk trade routes. With needs
 * posted and priced by the labor market (TWT-97), an individual trader from a settlement with something to
 * spare answers a distant settlement's shortfall — for profit where the price gradient pays, or out of
 * generosity (mutual aid as an action) where it doesn't. "The village is starving; a trader arrives with grain."
 */
class CaravanTest extends TestCase
{
    private const TICK = 5 * 240 * 24;

    public function test_a_priced_shortfall_pulls_a_supplier_and_the_trader_profits(): void
    {
        $world = $this->world();
        $needy = $world->villages[0];               // empty granary → posts a dear food shortfall
        $needy->stockpile->add('money', 500.0);     // and can pay for relief
        $supplier = $world->foundVillage('Breadbasket', population: 6, x: 30.0, y: 0.0);
        $supplier->stockpile->add('food', 1_000.0); // abundant, so cheap there — a profitable haul

        $supplierFoodBefore = $supplier->stockpile->amount('food');
        $this->dispatch($world);

        $this->assertGreaterThan(0.0, $needy->stockpile->amount('food'), 'a trader answers the posted shortfall');
        $this->assertLessThan($supplierFoodBefore, $supplier->stockpile->amount('food'), 'carried from the settlement with a surplus');
        $this->assertLessThan(500.0, $needy->stockpile->amount('money'), 'the buyer pays the dear local price');

        $trader = $this->trader($supplier);
        $this->assertNotNull($trader, 'an individual trader is the actor');
        $this->assertGreaterThan(0.0, $trader->stockpile->amount('money'), 'and keeps the coin — personal wealth from trade');
    }

    public function test_a_generous_trader_helps_even_at_a_thin_margin(): void
    {
        $world = $this->world();
        $needy = $world->villages[0]; // empty granary, and no money to pay
        $supplier = $world->foundVillage('Faraid', population: 6, x: 0.0, y: 350.0); // far → the haul cannot profit
        $supplier->stockpile->add('food', 9.0 * 6); // barely above subsistence — a thin margin
        $supplier->mutualAid = 0.9;
        $giver = $supplier->livingAgents()[0];
        $giver->traits['generosity'] = 95.0; // one open-handed soul among them

        $this->dispatch($world);

        $this->assertGreaterThan(0.0, $needy->stockpile->amount('food'), 'relief comes even where there is no profit in it');
        $this->assertSame(0.0, $needy->stockpile->amount('money'), 'mutual aid asks nothing of the destitute');
        $this->assertGreaterThan(0, $giver->jobHistory['trading'] ?? 0, 'the most generous soul is the one who goes');
    }

    public function test_no_caravan_crosses_to_a_sworn_enemy(): void
    {
        $world = $this->world();
        $needy = $world->villages[0];
        $needy->stockpile->add('money', 500.0);
        $supplier = $world->foundVillage('Breadbasket', population: 6, x: 30.0, y: 0.0);
        $supplier->stockpile->add('food', 1_000.0);
        $world->relations[TradeEngine::routeKey($needy, $supplier)] = 0.0; // enmity

        $this->dispatch($world);

        $this->assertSame(0.0, $needy->stockpile->amount('food'), 'no trader carries grain to an enemy');
    }

    public function test_a_lone_settlement_is_a_no_op(): void
    {
        $world = $this->world();
        $before = $world->villages[0]->stockpile->amount('food');

        $this->dispatch($world);

        $this->assertSame($before, $world->villages[0]->stockpile->amount('food'), 'with no neighbour there is nowhere to carry anything, and no draw');
    }

    public function test_it_is_deterministic(): void
    {
        $a = $this->profitableWorld();
        $this->dispatch($a);
        $b = $this->profitableWorld();
        $this->dispatch($b);

        $this->assertSame(
            $a->villages[0]->stockpile->amount('food'),
            $b->villages[0]->stockpile->amount('food'),
            'a caravan is a pure function of its inputs',
        );
    }

    public function test_a_soul_who_trades_again_and_again_becomes_a_trader(): void
    {
        $world = $this->world();
        $trader = $world->villages[0]->livingAgents()[0];
        $trader->jobHistory = ['trading' => 30];

        ProfessionEngine::settle($trader);

        $this->assertSame('trading', $trader->profession, 'trading on the road settles into a trader by trade (TWT-98)');
    }

    private function world(): World
    {
        return World::seedTharadosVillage(new Rng('caravan'), 6);
    }

    private function profitableWorld(): World
    {
        $world = $this->world();
        $world->villages[0]->stockpile->add('money', 500.0);
        $supplier = $world->foundVillage('Breadbasket', population: 6, x: 30.0, y: 0.0);
        $supplier->stockpile->add('food', 1_000.0);

        return $world;
    }

    private function dispatch(World $world): void
    {
        CaravanEngine::runDay($world, self::TICK, TharadiCalendar::fromTick(self::TICK));
    }

    private function trader(Village $village): ?Agent
    {
        foreach ($village->livingAgents() as $agent) {
            if (($agent->jobHistory['trading'] ?? 0) > 0) {
                return $agent;
            }
        }

        return null;
    }
}
