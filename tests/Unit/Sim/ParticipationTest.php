<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Institutions\Institution;
use App\Sim\Projects\ProjectEngine;
use App\Sim\World\Agent;
use PHPUnit\Framework\TestCase;

class ParticipationTest extends TestCase
{
    private function agent(float $sociability): Agent
    {
        return new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', 0, ['sociability' => $sociability], []);
    }

    public function test_want_to_is_cohesion_times_sociability(): void
    {
        // cohesion 0.5 × sociability 50/100 = 0.25
        $this->assertEqualsWithDelta(0.25, ProjectEngine::participationWeight($this->agent(50.0), 0.5), 1e-9);
    }

    public function test_paid_to_fills_part_of_the_gap_toward_full(): void
    {
        // want-to 0.25, paid-to 0.4 → 0.25 + 0.4 × (1 − 0.25) = 0.55
        $this->assertEqualsWithDelta(
            0.55,
            ProjectEngine::participationWeight($this->agent(50.0), 0.5, null, 0.4),
            1e-9,
        );
    }

    public function test_the_three_axes_compose_and_stay_within_one(): void
    {
        $temple = Institution::emergeFor(Culture::tharados(), 0); // mandate 0.55, effectiveness 1.0
        $agent = $this->agent(50.0);

        // want-to 0.25 → +forced-to 0.55×0.75 = 0.6625 → +paid-to 0.4×(1−0.6625) = 0.135 → 0.7975
        $this->assertEqualsWithDelta(0.7975, ProjectEngine::participationWeight($agent, 0.5, $temple, 0.4), 1e-9);

        // Even maxed-out axes never exceed full participation.
        $this->assertLessThanOrEqual(1.0, ProjectEngine::participationWeight($this->agent(100.0), 1.0, $temple, 1.0));
    }
}
