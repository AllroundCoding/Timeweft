<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Institutions\Institution;
use PHPUnit\Framework\TestCase;

class InstitutionEmergenceTest extends TestCase
{
    private function culture(float $piety): Culture
    {
        return new Culture('Test', collectivism: 60, hierarchy: 50, tradition: 50, longTermOrientation: 50, restraint: 50, achievement: 50, piety: $piety);
    }

    public function test_a_devout_culture_gives_rise_to_the_temple_of_nara(): void
    {
        $institution = Institution::emergeFor(Culture::tharados(), 1234);

        $this->assertSame('Temple of Nara', $institution->name);
        $this->assertSame('temple', $institution->type);
        $this->assertSame(1234, $institution->foundedTick);
        $this->assertEqualsWithDelta(0.55, $institution->mandate, 1e-9);
    }

    public function test_a_secular_culture_falls_back_to_a_council(): void
    {
        $institution = Institution::emergeFor($this->culture(piety: 20), 0);

        $this->assertSame('council', $institution->type);
        $this->assertEqualsWithDelta(0.40, $institution->mandate, 1e-9);
    }

    public function test_lifted_participation_fills_the_gap_toward_full_effort(): void
    {
        $temple = Institution::emergeFor(Culture::tharados(), 0); // mandate 0.55

        // want-to 0 → supplied entirely by the institution's mandate.
        $this->assertEqualsWithDelta(0.55, $temple->liftedParticipation(0.0), 1e-9);
        // already full → stays full.
        $this->assertEqualsWithDelta(1.0, $temple->liftedParticipation(1.0), 1e-9);
        // partial want-to lifted toward 1: 0.2 + 0.55 * 0.8 = 0.64.
        $this->assertEqualsWithDelta(0.64, $temple->liftedParticipation(0.2), 1e-9);
    }

    public function test_lift_never_lowers_participation_or_exceeds_one(): void
    {
        $temple = Institution::emergeFor(Culture::tharados(), 0);

        foreach ([0.0, 0.16, 0.33, 0.5, 0.85, 1.0] as $wantTo) {
            $lifted = $temple->liftedParticipation($wantTo);
            $this->assertGreaterThanOrEqual($wantTo, $lifted);
            $this->assertLessThanOrEqual(1.0, $lifted);
        }
    }

    public function test_ossification_decays_effectiveness_and_its_lift(): void
    {
        $temple = Institution::emergeFor(Culture::tharados(), 0);
        $freshLift = $temple->liftedParticipation(0.2); // 0.64 at full effectiveness

        $temple->ossify(0.5);

        $this->assertEqualsWithDelta(0.5, $temple->effectiveness, 1e-9);
        // 0.2 + 0.55 × 0.5 × 0.8 = 0.42
        $this->assertEqualsWithDelta(0.42, $temple->liftedParticipation(0.2), 1e-9);
        $this->assertLessThan($freshLift, $temple->liftedParticipation(0.2));
    }

    public function test_has_ossified_once_effectiveness_reaches_the_threshold(): void
    {
        $temple = Institution::emergeFor(Culture::tharados(), 0);
        $this->assertFalse($temple->hasOssified(0.4));

        $temple->ossify(0.7); // effectiveness → 0.3

        $this->assertTrue($temple->hasOssified(0.4));
    }
}
