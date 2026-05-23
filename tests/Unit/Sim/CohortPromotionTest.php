<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Support\NameGenerator;
use App\Sim\Support\Rng;
use App\Sim\World\Cohort;
use App\Sim\World\CohortEngine;
use App\Sim\World\RegionProfile;
use App\Sim\World\Species;
use PHPUnit\Framework\TestCase;

/**
 * TWT-50: promotion/demotion makes LOD seamless — a cohort member becomes a fully-tracked individual
 * the moment they matter, and folds back into the count when attention leaves. "Materialize on
 * observation" applied to existence; the boundary conserves population both ways.
 */
class CohortPromotionTest extends TestCase
{
    private const TICK = 100 * 240 * 24; // a tick well into the run, so promoted adults have positive birth ticks

    public function test_promotion_materializes_a_real_individual_from_the_distribution(): void
    {
        $cohort = Cohort::ofAdults(1_000.0, 18, 50);
        [$agent] = CohortEngine::promote($cohort, ...$this->generators(1));

        $this->assertTrue($agent->alive, 'a living, tracked individual');
        $this->assertNotSame('', $agent->name, 'with a culture-coined name');
        $this->assertNotNull($agent->trait('agility'), 'and real traits');
        $age = $agent->ageInYears(self::TICK);
        $this->assertGreaterThanOrEqual(18, $age, 'of an age the cohort actually holds');
        $this->assertLessThanOrEqual(50, $age);
    }

    public function test_promotion_takes_one_soul_from_the_cohort(): void
    {
        $cohort = Cohort::ofAdults(1_000.0, 18, 50);
        [, $reduced] = CohortEngine::promote($cohort, ...$this->generators(1));

        $this->assertEqualsWithDelta($cohort->population() - 1.0, $reduced->population(), 1e-9, 'one head leaves the count');
    }

    public function test_promote_then_demote_conserves_the_population(): void
    {
        $cohort = Cohort::ofAdults(1_000.0, 18, 50);

        [$agent, $reduced] = CohortEngine::promote($cohort, ...$this->generators(7));
        $restored = CohortEngine::demote($reduced, $agent, self::TICK);

        $this->assertEqualsWithDelta($cohort->population(), $restored->population(), 1e-9, 'round-trip across the LOD boundary conserves people');
        $this->assertEqualsWithDelta($cohort->byAge, $restored->byAge, 1e-9, 'and restores the age distribution');
    }

    public function test_promotion_is_reproducible(): void
    {
        $cohort = Cohort::ofAdults(1_000.0, 18, 50);

        [$first] = CohortEngine::promote($cohort, ...$this->generators(42));
        [$second] = CohortEngine::promote($cohort, ...$this->generators(42));

        $this->assertSame($first->name, $second->name, 'same seed + id → the same person');
        $this->assertSame($first->ageInYears(self::TICK), $second->ageInYears(self::TICK));
    }

    /**
     * The generative collaborators a promotion needs, plus the id/tick/rng/names — spread into promote().
     *
     * @return array{0: Species, 1: RegionProfile, 2: Culture, 3: int, 4: int, 5: Rng, 6: NameGenerator}
     */
    private function generators(int $id): array
    {
        return [
            Species::vulpini(),
            RegionProfile::tharados(),
            Culture::tharados(),
            $id,
            self::TICK,
            new Rng('lod'),
            NameGenerator::vaeris(),
        ];
    }
}
