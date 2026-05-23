<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Economy\Stockpile;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\DistressEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-184: a failing settlement sends for help. Sustained famine draws gratis relief from amicable
 * neighbours with food to spare; sworn enemies send nothing; and a settlement that empties anyway is
 * mourned with a collapse beat rather than fading in silence.
 */
class DistressTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_amicable_neighbours_relieve_a_stricken_settlement(): void
    {
        $stricken = $this->village('Dusthold', 10, ['food' => 2.0]);
        $stricken->inFamine = true;
        $stricken->famineYears = 3; // long past coping
        $donor = $this->village('Breadbasket', 6, ['food' => 300.0]);

        $world = new World(new Rng('aid'));
        $world->villages = [$stricken, $donor];
        // (neutral relations by default → the donor will help)

        $tick = (int) self::TICKS_PER_YEAR;
        DistressEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $this->assertGreaterThan(2.0, $stricken->stockpile->amount('food'), 'relief arrives');
        $this->assertLessThan(300.0, $donor->stockpile->amount('food'), 'the neighbour shared its stores');
        $aid = array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'aid');
        $this->assertNotEmpty($aid, 'the relief is chronicled');
    }

    public function test_an_enemy_is_left_to_starve(): void
    {
        $stricken = $this->village('Dusthold', 10, ['food' => 2.0]);
        $stricken->inFamine = true;
        $stricken->famineYears = 3;
        $enemy = $this->village('Foehold', 6, ['food' => 300.0]);

        $world = new World(new Rng('aid'));
        $world->villages = [$stricken, $enemy];
        $world->relations['Dusthold↔Foehold'] = 0.05; // sworn enemies

        $tick = (int) self::TICKS_PER_YEAR;
        DistressEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $this->assertEqualsWithDelta(2.0, $stricken->stockpile->amount('food'), 1e-9, 'no enemy sends relief');
        $this->assertEqualsWithDelta(300.0, $enemy->stockpile->amount('food'), 1e-9, 'and keeps its own');
    }

    public function test_an_emptied_settlement_is_mourned_once(): void
    {
        $dead = new Village('Lasthold', 'Tharados');
        $dead->stockpile = new Stockpile;
        $dead->agents = [new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', 0, ['agility' => 50.0], [])];
        $dead->agents[0]->alive = false; // founded, now all gone

        $world = new World(new Rng('end'));
        $world->villages = [$dead];

        $first = (int) self::TICKS_PER_YEAR;
        DistressEngine::runDay($world, $first, TharadiCalendar::fromTick($first));
        $second = 2 * (int) self::TICKS_PER_YEAR;
        DistressEngine::runDay($world, $second, TharadiCalendar::fromTick($second));

        $collapses = array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'collapse');
        $this->assertCount(1, $collapses, 'the collapse is mourned exactly once');
        $this->assertTrue($dead->collapsed);
    }

    public function test_a_thriving_settlement_is_neither_relieved_nor_mourned(): void
    {
        $a = $this->village('Sunwell', 8, ['food' => 100.0]);
        $b = $this->village('Khoradun', 8, ['food' => 100.0]);
        $world = new World(new Rng('ok'));
        $world->villages = [$a, $b];

        $tick = (int) self::TICKS_PER_YEAR;
        DistressEngine::runDay($world, $tick, TharadiCalendar::fromTick($tick));

        $this->assertSame([], $world->chronicle->all(), 'no famine, no exodus → no beats');
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
