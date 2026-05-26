<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\Circulation;
use App\Sim\Worldgen\CirculationGenerator;
use App\Sim\Worldgen\SubstrateGenerator;
use PHPUnit\Framework\TestCase;

/**
 * TWT-77 — atmospheric & ocean circulation over the substrate. Deterministic per seed, and the wind field
 * is a field of unit direction vectors (so downstream climate can read a clean heading at every cell).
 */
class CirculationGeneratorTest extends TestCase
{
    public function test_circulation_is_a_pure_function_of_the_world(): void
    {
        $a = $this->circulation();
        $b = $this->circulation();

        $this->assertSame($a->windU, $b->windU, 'same seed → the same winds');
        $this->assertSame($a->windV, $b->windV, 'same seed → the same winds');
        $this->assertSame($a->currentTemp, $b->currentTemp, 'same seed → the same currents');
    }

    public function test_wind_vectors_are_normalized(): void
    {
        $circulation = $this->circulation();

        for ($y = 0; $y < $circulation->height; $y += 7) {
            for ($x = 0; $x < $circulation->width; $x += 7) {
                [$u, $v] = $circulation->windAt($x, $y);
                $this->assertEqualsWithDelta(1.0, hypot($u, $v), 1.0e-9, 'wind is a unit direction vector');
            }
        }
    }

    private function circulation(): Circulation
    {
        $rng = new Rng('vaeris');
        $substrate = SubstrateGenerator::generate($rng, 80, 50, 10);

        return CirculationGenerator::generate($rng, $substrate);
    }
}
