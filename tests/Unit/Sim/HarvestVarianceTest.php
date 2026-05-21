<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use App\Sim\Support\Rng;
use PHPUnit\Framework\TestCase;

class HarvestVarianceTest extends TestCase
{
    public function test_a_good_year_beats_an_average_one_beats_a_lean_one(): void
    {
        $good = EconomyEngine::harvestQuality(1.0, 0.5);
        $average = EconomyEngine::harvestQuality(0.0, 0.5);
        $lean = EconomyEngine::harvestQuality(-1.0, 0.5);

        $this->assertGreaterThan($average, $good);
        $this->assertGreaterThan($lean, $average);
        $this->assertEqualsWithDelta(1.0, $average, 1e-9); // an average roll is exactly the mean
    }

    public function test_a_stable_region_has_no_harvest_swing(): void
    {
        // Zero volatility → every year is the average, however the roll falls.
        $this->assertEqualsWithDelta(1.0, EconomyEngine::harvestQuality(1.0, 0.0), 1e-9);
        $this->assertEqualsWithDelta(1.0, EconomyEngine::harvestQuality(-1.0, 0.0), 1e-9);
    }

    public function test_a_volatile_region_swings_wider_than_a_calm_one(): void
    {
        $volatile = EconomyEngine::harvestQuality(1.0, 0.9);
        $calm = EconomyEngine::harvestQuality(1.0, 0.2);

        $this->assertGreaterThan($calm, $volatile);
    }

    public function test_the_lean_year_never_falls_below_the_floor(): void
    {
        // Even the worst ordinary year leaves something — catastrophe is the shock engine's job.
        $this->assertGreaterThanOrEqual(0.2, EconomyEngine::harvestQuality(-1.0, 1.0));
    }

    public function test_a_forked_substream_is_reproducible_yet_independent(): void
    {
        $rng = new Rng('vaeris');
        $a = $rng->fork('harvest/3')->float(-1.0, 1.0);
        $b = $rng->fork('harvest/3')->float(-1.0, 1.0);

        // Same salt → same draw (reproducible), and forking did not advance the parent stream.
        $this->assertSame($a, $b);
        $this->assertNotSame($rng->fork('harvest/4')->float(-1.0, 1.0), $a);
    }
}
