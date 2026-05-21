<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\EconomyEngine;
use PHPUnit\Framework\TestCase;

class TechnologyRatchetTest extends TestCase
{
    public function test_pressure_is_the_spur_to_innovate(): void
    {
        // Boserup: a settlement pressed against its ceiling intensifies; a sparse one has no reason to.
        $pressed = EconomyEngine::technologyGrowth(1.0, 1.0, 0.6);
        $sparse = EconomyEngine::technologyGrowth(0.3, 1.0, 0.6);

        $this->assertGreaterThan($sparse, $pressed);
        $this->assertGreaterThan(0.0, $pressed);
    }

    public function test_a_starving_settlement_cannot_innovate(): void
    {
        // No surplus to spare → no intensification, however hard the pressure.
        $this->assertEqualsWithDelta(0.0, EconomyEngine::technologyGrowth(1.2, 0.0, 0.6), 1e-9);
    }

    public function test_a_closed_culture_does_not_innovate(): void
    {
        $this->assertEqualsWithDelta(0.0, EconomyEngine::technologyGrowth(1.0, 1.0, 0.0), 1e-9);
    }

    public function test_an_open_culture_innovates_faster_than_a_traditional_one(): void
    {
        $open = EconomyEngine::technologyGrowth(1.0, 1.0, 0.8);
        $traditional = EconomyEngine::technologyGrowth(1.0, 1.0, 0.2);

        $this->assertGreaterThan($traditional, $open);
    }

    public function test_growth_only_ever_ratchets_upward(): void
    {
        // Growth is never negative — knowledge, once won, sticks.
        $this->assertGreaterThanOrEqual(0.0, EconomyEngine::technologyGrowth(0.0, 1.0, 0.6));
        $this->assertGreaterThanOrEqual(0.0, EconomyEngine::technologyGrowth(-0.5, 1.0, 0.6));
    }
}
