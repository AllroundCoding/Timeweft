<?php

namespace Tests\Unit\Sim;

use App\Sim\World\Cohort;
use App\Sim\World\CohortEngine;
use PHPUnit\Framework\TestCase;

/**
 * TWT-49: a statistical cohort is the mean-field of the tracked simulation — it carries a settlement
 * as counts-by-age advanced by the same age-specific mortality and density-dependent fertility
 * (reusing EmergenceEngine's rates), so it grows logistically toward carrying capacity, declines from
 * above it, and costs the same whether it stands for fifty souls or fifty thousand.
 */
class CohortEngineTest extends TestCase
{
    public function test_it_is_deterministic(): void
    {
        $start = Cohort::ofAdults(8.0);

        $a = CohortEngine::advanceYear($start, 20.0);
        $b = CohortEngine::advanceYear($start, 20.0);

        $this->assertSame($a->byAge, $b->byAge, 'a cohort is a pure function of its inputs');
    }

    public function test_a_cohort_below_capacity_grows_toward_it(): void
    {
        $cohort = Cohort::ofAdults(8.0);
        for ($year = 0; $year < 25; $year++) {
            $cohort = CohortEngine::advanceYear($cohort, 20.0);
        }

        $this->assertGreaterThan(8.0, $cohort->population(), 'a young settlement grows');
        $this->assertLessThan(20.0, $cohort->population(), 'but density-dependent fertility holds it under K');
    }

    public function test_a_cohort_over_capacity_declines_toward_it(): void
    {
        $cohort = Cohort::ofAdults(60.0);
        for ($year = 0; $year < 25; $year++) {
            $cohort = CohortEngine::advanceYear($cohort, 20.0);
        }

        $this->assertLessThan(60.0, $cohort->population(), 'overshoot dies back');
        $this->assertGreaterThan(20.0, $cohort->population(), 'still on its way down toward K');
    }

    public function test_an_advanced_cohort_keeps_a_real_age_structure(): void
    {
        $cohort = Cohort::ofAdults(8.0);
        for ($year = 0; $year < 20; $year++) {
            $cohort = CohortEngine::advanceYear($cohort, 20.0);
        }

        $this->assertGreaterThan(0.0, $cohort->inAgeRange(0, 15), 'children are being born and growing up');
        $this->assertGreaterThan(0.0, $cohort->inAgeRange(16, 60), 'and there are adults');
        $this->assertSame([], array_filter($cohort->byAge, static fn (int $age): bool => $age > 90, ARRAY_FILTER_USE_KEY), 'no one outlives the lifespan');
    }

    public function test_it_scales_to_a_city_at_the_cost_of_a_village(): void
    {
        // The point of the LOD primitive: a fifty-thousand-soul cohort advances in O(age bands).
        $city = Cohort::ofAdults(50_000.0);

        $advanced = CohortEngine::advanceYear($city, 200_000.0);

        $this->assertGreaterThan(50_000.0, $advanced->population(), 'a city well below its ceiling grows');
        $this->assertLessThanOrEqual(91, count($advanced->byAge), 'held as age bands, not individuals');
    }
}
