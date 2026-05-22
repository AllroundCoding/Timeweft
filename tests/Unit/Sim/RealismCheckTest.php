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
 * Oracle: docs/lore/canon/regions/tharados.yaml and aetheria.yaml (see docs/lore/realism.md). The
 * piety axis is deliberately excluded from the directional check: it is a magic_exception for
 * Aetheria (devotion stays rational under prosperity because magic is real), so a purely-material
 * prediction is not expected to hold. Hierarchy used to be excluded too, but TWT-121 now models it
 * from land-tenure concentration rather than scarcity, so it is checked here directly across four
 * regions the old scarcity-driven rule could not satisfy at once.
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

    public function test_hierarchy_tracks_land_tenure_not_scarcity_across_four_regions(): void
    {
        // Four canon regions whose hierarchy a scarcity-only rule cannot fit at once — two harsh-cold
        // lands (Draknar, Frostlands) sit at opposite ends of hierarchy. (scarcity, volatility,
        // land-tenure concentration) from docs/lore/canon/regions/*.yaml + the structural notes there.
        $tharados = Culture::fromMaterialConditions('Tharadi', 0.75, 0.50, 0.90);    // imperial — concentrated oases
        $aetheria = Culture::fromMaterialConditions('Aetherian', 0.40, 0.43, 0.70);  // feudal — surplus + estates
        $draknar = Culture::fromMaterialConditions('Draknar', 0.74, 0.53, 0.40);     // fragmented warrior clans
        $frostlands = Culture::fromMaterialConditions('Frost', 0.85, 0.40, 0.12);    // egalitarian survivalist bands

        // Canon ranks: Tharados high, Aetheria moderate-high, Draknar moderate, Frostlands low.
        $this->assertGreaterThan(65.0, $tharados->hierarchy, 'the desert empire is steeply hierarchical');
        $this->assertGreaterThan(55.0, $aetheria->hierarchy, 'the feudal kingdom is hierarchical');
        $this->assertGreaterThan(38.0, $draknar->hierarchy, 'clan chieftains give Draknar middling hierarchy');
        $this->assertLessThan(58.0, $draknar->hierarchy, 'but no central state keeps it from the top');
        $this->assertLessThan(40.0, $frostlands->hierarchy, 'egalitarian bands have little hierarchy');

        // The two harsh-cold lands diverge sharply — proof scarcity is not the lever.
        $this->assertGreaterThan($frostlands->hierarchy, $draknar->hierarchy, 'same harsh cold, different polity');
        // And the overall ordering follows concentration, not scarcity.
        $this->assertGreaterThan($aetheria->hierarchy, $tharados->hierarchy, 'the empire out-ranks the feudal kingdom');
        $this->assertGreaterThan($draknar->hierarchy, $aetheria->hierarchy, 'the feudal kingdom out-ranks the clans');
    }
}
