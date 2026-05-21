<?php

namespace Tests\Unit\Sim;

use App\Sim\World\Village;
use PHPUnit\Framework\TestCase;

class VillageCohesionTest extends TestCase
{
    private function village(): Village
    {
        return new Village('Sunwell Oasis', 'Tharados');
    }

    public function test_a_tiny_settlement_cooperates_near_its_cultural_baseline(): void
    {
        $village = $this->village();

        $this->assertGreaterThan(0.84, $village->cohesion(1));
        $this->assertLessThanOrEqual($village->baselineCohesion, $village->cohesion(1));
    }

    public function test_cohesion_at_the_cohesive_group_size_is_the_midpoint(): void
    {
        $village = $this->village();

        // At populationSize == cohesiveGroupSize the decay term is exactly 1/2.
        $expected = $village->cohesionFloor + ($village->baselineCohesion - $village->cohesionFloor) / 2.0;
        $this->assertEqualsWithDelta($expected, $village->cohesion($village->cohesiveGroupSize), 1e-9);
    }

    public function test_cohesion_decays_monotonically_with_size(): void
    {
        $village = $this->village();

        $this->assertGreaterThan($village->cohesion(10), $village->cohesion(2));
        $this->assertGreaterThan($village->cohesion(20), $village->cohesion(10));
        $this->assertGreaterThan($village->cohesion(40), $village->cohesion(20));
    }

    public function test_cohesion_stays_between_floor_and_baseline(): void
    {
        $village = $this->village();

        foreach ([0, 1, 5, 15, 50, 500] as $size) {
            $cohesion = $village->cohesion($size);
            $this->assertGreaterThanOrEqual($village->cohesionFloor, $cohesion);
            $this->assertLessThanOrEqual($village->baselineCohesion, $cohesion);
        }
    }

    public function test_a_crowded_settlement_approaches_the_floor(): void
    {
        $village = $this->village();

        $cohesion = $village->cohesion(1000);
        $this->assertGreaterThanOrEqual($village->cohesionFloor, $cohesion);
        $this->assertLessThan($village->cohesionFloor + 0.01, $cohesion);
    }
}
