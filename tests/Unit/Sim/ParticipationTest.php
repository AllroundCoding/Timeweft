<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Culture\Faith;
use App\Sim\Institutions\Institution;
use App\Sim\Projects\ProjectEngine;
use App\Sim\World\Agent;
use App\Sim\World\Need;
use PHPUnit\Framework\TestCase;

class ParticipationTest extends TestCase
{
    private function agent(float $sociability): Agent
    {
        return new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', 0, ['sociability' => $sociability], []);
    }

    public function test_want_to_is_cohesion_times_sociability(): void
    {
        // cohesion 0.5 × sociability 50/100 × conscientiousness factor 1.0 (default midpoint) = 0.25
        $this->assertEqualsWithDelta(0.25, ProjectEngine::participationWeight($this->agent(50.0), 0.5), 1e-9);
    }

    public function test_a_binding_faith_lifts_the_devout_toward_cooperation(): void
    {
        $faith = Faith::fromCulture('Pious', new Culture('C', collectivism: 90, hierarchy: 80, tradition: 80, longTermOrientation: 50, restraint: 50, achievement: 50, piety: 90));
        $devout = new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', 0, ['sociability' => 50.0, 'conscientiousness' => 90.0, 'generosity' => 90.0], []);
        $nominal = new Agent(2, 'B', 'Vulpini', 'Tharados', 'm', 0, ['sociability' => 50.0, 'conscientiousness' => 10.0, 'generosity' => 10.0], []);

        $withoutFaith = ProjectEngine::participationWeight($devout, 0.5);
        $devoutWithFaith = ProjectEngine::participationWeight($devout, 0.5, null, 0.0, $faith);
        $nominalWithFaith = ProjectEngine::participationWeight($nominal, 0.5, null, 0.0, $faith);

        // The same faith lifts the devout, but barely touches the nominal believer.
        $this->assertGreaterThan($withoutFaith, $devoutWithFaith);
        $this->assertGreaterThan($nominalWithFaith, $devoutWithFaith);
    }

    public function test_conscientiousness_lifts_contribution(): void
    {
        $diligent = new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', 0, ['sociability' => 50.0, 'conscientiousness' => 90.0], []);
        $lax = new Agent(2, 'B', 'Vulpini', 'Tharados', 'f', 0, ['sociability' => 50.0, 'conscientiousness' => 10.0], []);

        $this->assertGreaterThan(
            ProjectEngine::participationWeight($lax, 0.5),
            ProjectEngine::participationWeight($diligent, 0.5),
        );
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

    public function test_sickness_saps_the_capacity_to_work(): void
    {
        $healthy = new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', 0, ['sociability' => 50.0], ['sickness' => new Need('sickness', 0.0, 0.0)]);
        $sick = new Agent(2, 'B', 'Vulpini', 'Tharados', 'f', 0, ['sociability' => 50.0], ['sickness' => new Need('sickness', 80.0, 0.0)]);

        // want-to is 0.25 for both; illness scales the effort down by sickness/100 × 0.75.
        $this->assertEqualsWithDelta(0.25, ProjectEngine::participationWeight($healthy, 0.5), 1e-9, 'a well agent works at full want-to');
        $this->assertEqualsWithDelta(0.1, ProjectEngine::participationWeight($sick, 0.5), 1e-9, 'a gravely ill one contributes far less (0.25 × 0.4)');
        $this->assertLessThan(
            ProjectEngine::participationWeight($healthy, 0.5),
            ProjectEngine::participationWeight($sick, 0.5),
            'illness saps the capacity to work, not just the will to live',
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
