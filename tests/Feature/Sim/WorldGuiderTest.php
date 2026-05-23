<?php

namespace Tests\Feature\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\Village;
use App\Sim\World\World;
use App\Sim\World\WorldGuider;
use PHPUnit\Framework\TestCase;

/**
 * TWT-90: the world guider's hard-rules tier keeps generation within bounds. The normal seeded run
 * trips nothing (so it stays byte-identical), out-of-bounds scalars are clamped back, and a
 * degenerate population is flagged for the engine to resolve.
 */
class WorldGuiderTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_healthy_seeded_run_trips_no_invariant(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 30);
        $world->advance(self::TICKS_PER_YEAR * 40);

        $this->assertSame([], $world->guardLog, 'a well-behaved run violates nothing');
    }

    public function test_it_clamps_out_of_bounds_scalars_back_within_range(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 6);
        $village = $world->village;

        // Shove state out of bounds, as a buggy system or a careless edit might.
        $village->dietQuality = 2.5;
        $village->mutualAid = -0.4;
        $village->starvationFactor = 0.2;
        $village->culture = new Culture('Tharadi', collectivism: 150.0, hierarchy: -20.0, tradition: 50.0, longTermOrientation: 50.0, restraint: 50.0, achievement: 50.0, piety: 50.0);

        $violations = WorldGuider::inspect($world, 0);

        $this->assertNotEmpty($violations);
        $this->assertTrue(array_reduce($violations, static fn (bool $c, $v): bool => $c && $v->corrected, true), 'every breach here is correctable');
        $this->assertEqualsWithDelta(1.0, $village->dietQuality, 1e-9);
        $this->assertEqualsWithDelta(0.0, $village->mutualAid, 1e-9);
        $this->assertEqualsWithDelta(1.0, $village->starvationFactor, 1e-9);
        $this->assertEqualsWithDelta(100.0, $village->culture->collectivism, 1e-9, 'culture is clamped to its band');
        $this->assertEqualsWithDelta(0.0, $village->culture->hierarchy, 1e-9);
    }

    public function test_a_degenerate_population_is_flagged_not_culled(): void
    {
        // A settlement holding far more than its land can feed — the canon's "2,000 souls on a 200-soul oasis".
        $crowded = new Village('Overholm', 'Tharados', landYield: 4.0);
        $crowded->carryingCapacity = 4;
        $crowded->agents = array_map(
            fn (int $i): Agent => new Agent($i, "A{$i}", 'Vulpini', 'Tharados', 'f', -25 * self::TICKS_PER_YEAR, ['agility' => 50.0], []),
            range(1, 40),
        );

        $world = new World(new Rng('guard'));
        $world->villages = [$crowded];
        $world->village = $crowded;

        $violations = WorldGuider::inspect($world, 0);

        $overshoot = array_values(array_filter($violations, static fn ($v): bool => $v->rule === 'population-overshoot'));
        $this->assertNotEmpty($overshoot, 'gross overcrowding is flagged');
        $this->assertFalse($overshoot[0]->corrected, 'the guard flags it but does not cull people');
        $this->assertCount(40, $crowded->agents, 'no one was removed');
    }
}
