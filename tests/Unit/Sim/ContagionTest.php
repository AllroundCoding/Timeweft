<?php

namespace Tests\Unit\Sim;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\ContagionEngine;
use App\Sim\World\TradeEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-79: disease is a network phenomenon, not a village-bound event — an outbreak rides trade routes
 * and proximity into a settlement's neighbours with a lag, sparing the truly isolated, with how hard a
 * place is hit tracking how connected it is. The Black Death on the sea lanes and the Silk Road.
 */
class ContagionTest extends TestCase
{
    private const TICK = 100 * 240 * 24; // well into the run; the exact day only colours the chronicle

    public function test_a_plague_rides_proximity_into_neighbours_and_severity_tracks_distance(): void
    {
        $world = $this->seedOutbreak();
        $near = $world->foundVillage('Nearhaven', x: 30.0, y: 0.0);  // proximity 0.75
        $mid = $world->foundVillage('Midreach', x: 90.0, y: 0.0);    // proximity 0.25
        $far = $world->foundVillage('Farhold', x: 250.0, y: 250.0);  // out of contact range, on no route

        $this->spread($world, 150);

        $this->assertGreaterThan(0.0, $this->mean($near), 'a near neighbour catches it');
        $this->assertGreaterThan($this->mean($mid), $this->mean($near), 'the closer settlement is harder hit');
        $this->assertGreaterThan(0.0, $this->mean($mid), 'the mid settlement catches it too, but milder');
        $this->assertSame(0.0, $this->mean($far), 'the isolated settlement is spared');
    }

    public function test_a_trade_route_carries_the_plague_across_any_distance(): void
    {
        $world = $this->seedOutbreak();
        $origin = $world->villages[0];
        $routed = $world->foundVillage('Searoad Port', x: 250.0, y: 250.0);  // far, but on a mature lane
        $isolated = $world->foundVillage('Lonereach', x: -250.0, y: 250.0);  // equally far, on no lane

        // A long-established shipping lane between the origin and the far port.
        $world->routes[TradeEngine::routeKey($origin, $routed)] = ['ageYears' => 20, 'lastYear' => 0];

        $this->spread($world, 150);

        $this->assertGreaterThan(0.0, $this->mean($routed), 'the lane carries the plague across the sea');
        $this->assertSame(0.0, $this->mean($isolated), 'a settlement the same distance away on no route is spared');
    }

    public function test_the_chronicle_records_where_a_spreading_outbreak_reaches(): void
    {
        $world = $this->seedOutbreak();
        $near = $world->foundVillage('Nearhaven', x: 20.0, y: 0.0);
        $far = $world->foundVillage('Farhold', x: 250.0, y: 250.0);

        $this->spread($world, 220);

        $this->assertTrue($near->inOutbreak, 'the neighbour is gripped by the spread');
        $contagion = array_values(array_filter(
            $world->chronicle->all(),
            static fn ($e): bool => $e->type === 'contagion',
        ));
        $this->assertNotEmpty($contagion, 'the day the plague reaches a neighbour is chronicled');
        $this->assertStringContainsString($near->name, $contagion[0]->text, 'and names where it reached');
        $this->assertNotNull($near->lastPlagueEventId, 'so deaths there can cite the outbreak as their cause');
        $this->assertFalse($far->inOutbreak, 'the isolated settlement never catches it');
    }

    public function test_it_is_deterministic(): void
    {
        $a = $this->seedOutbreak();
        $a->foundVillage('Nearhaven', x: 30.0, y: 0.0);
        $this->spread($a, 100);

        $b = $this->seedOutbreak();
        $b->foundVillage('Nearhaven', x: 30.0, y: 0.0);
        $this->spread($b, 100);

        $this->assertSame($this->mean($a->villages[1]), $this->mean($b->villages[1]), 'contagion is a pure function of its inputs');
    }

    public function test_a_lone_settlement_is_a_no_op(): void
    {
        $world = $this->seedOutbreak();
        $before = $this->mean($world->villages[0]);

        ContagionEngine::runDay($world, self::TICK, TharadiCalendar::fromTick(self::TICK));

        $this->assertSame($before, $this->mean($world->villages[0]), 'with no neighbour there is nowhere to spread, and no draw');
    }

    /** A world whose single founding settlement is in the grip of a plague — every soul half-sick. */
    private function seedOutbreak(): World
    {
        $world = World::seedTharadosVillage(new Rng('plague'), 6);
        foreach ($world->villages[0]->livingAgents() as $agent) {
            $agent->needs['sickness']->value = 50.0;
        }

        return $world;
    }

    /** Advance contagion day by day from a tick well into the run. */
    private function spread(World $world, int $days): void
    {
        $tick = self::TICK;
        for ($day = 0; $day < $days; $day++) {
            $tick += TharadiCalendar::HOURS_PER_DAY;
            ContagionEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));
        }
    }

    private function mean(Village $village): float
    {
        $living = $village->livingAgents();
        if ($living === []) {
            return 0.0;
        }
        $total = 0.0;
        foreach ($living as $agent) {
            $total += $agent->needs['sickness']->value;
        }

        return $total / count($living);
    }
}
