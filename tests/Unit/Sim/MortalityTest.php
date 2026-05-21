<?php

namespace Tests\Unit\Sim;

use App\Sim\World\EmergenceEngine;
use PHPUnit\Framework\TestCase;

class MortalityTest extends TestCase
{
    public function test_mortality_is_u_shaped(): void
    {
        // The young and the old die; the middle years are the safe ones.
        $infant = EmergenceEngine::dailyMortality(0);
        $youth = EmergenceEngine::dailyMortality(22);
        $elder = EmergenceEngine::dailyMortality(75);

        $this->assertGreaterThan($youth, $infant);
        $this->assertGreaterThan($youth, $elder);
    }

    public function test_infant_mortality_is_materially_high(): void
    {
        // A newborn's daily risk compounds to a brutal first-year mortality (historically realistic).
        $annualSurvival = (1.0 - EmergenceEngine::dailyMortality(0)) ** 365;

        $this->assertLessThan(0.85, $annualSurvival); // >15% die in the first year
    }

    public function test_child_mortality_fades_with_age(): void
    {
        $this->assertGreaterThan(EmergenceEngine::dailyMortality(8), EmergenceEngine::dailyMortality(1));
        $this->assertGreaterThan(EmergenceEngine::dailyMortality(15), EmergenceEngine::dailyMortality(8));
    }

    public function test_a_healthy_well_fed_mother_is_safest_in_childbirth(): void
    {
        $safe = EmergenceEngine::maternalMortalityRisk(0.0, 1.0);
        $sick = EmergenceEngine::maternalMortalityRisk(80.0, 1.0);
        $starving = EmergenceEngine::maternalMortalityRisk(0.0, 0.2);

        $this->assertGreaterThan(0.0, $safe);
        $this->assertGreaterThan($safe, $sick);      // illness raises the risk
        $this->assertGreaterThan($safe, $starving);  // a poor diet raises it too
    }
}
