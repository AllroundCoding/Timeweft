<?php

namespace Tests\Unit\Sim;

use App\Sim\Institutions\Institution;
use PHPUnit\Framework\TestCase;

class InstitutionEmergenceTest extends TestCase
{
    public function test_tharados_culture_gives_rise_to_the_temple_of_nara(): void
    {
        $institution = Institution::emergeFor('Tharados', 1234);

        $this->assertSame('Temple of Nara', $institution->name);
        $this->assertSame('temple', $institution->type);
        $this->assertSame(1234, $institution->foundedTick);
        $this->assertEqualsWithDelta(0.55, $institution->mandate, 1e-9);
    }

    public function test_other_cultures_fall_back_to_a_council(): void
    {
        $institution = Institution::emergeFor('Elenwood', 0);

        $this->assertSame('council', $institution->type);
        $this->assertEqualsWithDelta(0.40, $institution->mandate, 1e-9);
    }

    public function test_lifted_participation_fills_the_gap_toward_full_effort(): void
    {
        $temple = Institution::emergeFor('Tharados', 0); // mandate 0.55

        // want-to 0 → supplied entirely by the institution's mandate.
        $this->assertEqualsWithDelta(0.55, $temple->liftedParticipation(0.0), 1e-9);
        // already full → stays full.
        $this->assertEqualsWithDelta(1.0, $temple->liftedParticipation(1.0), 1e-9);
        // partial want-to lifted toward 1: 0.2 + 0.55 * 0.8 = 0.64.
        $this->assertEqualsWithDelta(0.64, $temple->liftedParticipation(0.2), 1e-9);
    }

    public function test_lift_never_lowers_participation_or_exceeds_one(): void
    {
        $temple = Institution::emergeFor('Tharados', 0);

        foreach ([0.0, 0.16, 0.33, 0.5, 0.85, 1.0] as $wantTo) {
            $lifted = $temple->liftedParticipation($wantTo);
            $this->assertGreaterThanOrEqual($wantTo, $lifted);
            $this->assertLessThanOrEqual(1.0, $lifted);
        }
    }
}
