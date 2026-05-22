<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\World\RegionArchetype;
use PHPUnit\Framework\TestCase;

/**
 * TWT-46: the realism loop, checked against vendored canon. Cultural materialism says a region's
 * material conditions (scarcity, volatility) should predict its culture; the Vaeris canon records
 * that prediction per region in an `expected_culture` block. This test reads two contrasting regions
 * — the harsh desert and the fertile sownland — generates their cultures through the engine, and
 * asserts the result respects the canon's qualitative ordering.
 *
 * Oracle: docs/lore/canon/regions/tharados.yaml and aetheria.yaml (see docs/lore/realism.md). Two
 * axes are deliberately excluded, per the canon's own notes:
 *   - piety: a magic_exception for Aetheria (devotion stays rational under prosperity because magic
 *     is real), so a purely-material prediction is not expected to hold.
 *   - hierarchy: the canon flags it as tracking surplus/land-tenure, not scarcity alone, so the
 *     engine's scarcity-driven mapping is a known oversimplification here.
 */
class RealismCheckTest extends TestCase
{
    public function test_archetype_material_conditions_match_the_canon(): void
    {
        // docs/lore/canon/regions/tharados.yaml → derived: scarcity 0.75, seasonal_volatility 0.50.
        $desert = RegionArchetype::desert()->toRegionProfile();
        $this->assertEqualsWithDelta(0.75, $desert->scarcity(), 1e-9, 'desert scarcity matches canon');
        $this->assertEqualsWithDelta(0.50, $desert->seasonalVolatility(), 1e-9, 'desert volatility matches canon');

        // docs/lore/canon/regions/aetheria.yaml → derived: scarcity 0.40, seasonal_volatility 0.43.
        $sownland = RegionArchetype::sownland()->toRegionProfile();
        $this->assertEqualsWithDelta(0.40, $sownland->scarcity(), 0.01, 'sownland scarcity matches canon');
        $this->assertEqualsWithDelta(0.43, $sownland->seasonalVolatility(), 0.01, 'sownland volatility matches canon');
    }

    public function test_generated_cultures_follow_the_canon_expected_ordering(): void
    {
        $desert = RegionArchetype::desert()->toRegionProfile();
        $sownland = RegionArchetype::sownland()->toRegionProfile();

        $tharadi = Culture::fromMaterialConditions('Tharadi', $desert->scarcity(), $desert->seasonalVolatility());
        $aetherian = Culture::fromMaterialConditions('Aetherian', $sownland->scarcity(), $sownland->seasonalVolatility());

        // Canon: Tharados collectivism/tradition/long_term_orientation/restraint all "high"; Aetheria
        // "moderate" or "low-moderate". The harsh desert generates the tighter, more survival-oriented culture.
        $this->assertGreaterThan($aetherian->collectivism, $tharadi->collectivism, 'the desert is more collectivist');
        $this->assertGreaterThan($aetherian->tradition, $tharadi->tradition, 'the desert is more traditional');
        $this->assertGreaterThan($aetherian->longTermOrientation, $tharadi->longTermOrientation, 'the desert plans longer against the lean season');
        $this->assertGreaterThan($aetherian->restraint, $tharadi->restraint, 'the desert is more restrained');

        // Canon: Tharados achievement "low-moderate"; Aetheria "moderate-high" — abundance affords
        // mobility through skill (guilds, academies). The fertile land prizes achievement more.
        $this->assertLessThan($aetherian->achievement, $tharadi->achievement, 'the sownland prizes achievement more');
    }
}
