<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\World\RegionProfile;
use App\Sim\World\Village;
use PHPUnit\Framework\TestCase;

class CultureTest extends TestCase
{
    public function test_a_harsh_land_breeds_restraint_collectivism_and_piety(): void
    {
        $harsh = Culture::fromMaterialConditions('Harsh', scarcity: 1.0, volatility: 0.5);
        $abundant = Culture::fromMaterialConditions('Lush', scarcity: 0.0, volatility: 0.0);

        $this->assertGreaterThan($abundant->restraint, $harsh->restraint);
        $this->assertGreaterThan($abundant->collectivism, $harsh->collectivism);
        $this->assertGreaterThan($abundant->piety, $harsh->piety);
        // Abundance breeds achievement/competition over communal welfare.
        $this->assertGreaterThan($harsh->achievement, $abundant->achievement);
        // Volatility breeds long-term orientation (plan/save for the lean season).
        $this->assertGreaterThan(
            Culture::fromMaterialConditions('Stable', 0.5, 0.0)->longTermOrientation,
            Culture::fromMaterialConditions('Swingy', 0.5, 1.0)->longTermOrientation,
        );
    }

    public function test_the_tharadi_culture_is_derived_from_its_region(): void
    {
        $region = RegionProfile::tharados();
        $derived = Culture::fromMaterialConditions('Tharadi', $region->scarcity(), $region->seasonalVolatility());

        // The Tharados desert (scarcity 0.75, volatility 0.5) reproduces the historical hand-tuned vector.
        $this->assertEqualsWithDelta(85.0, $derived->collectivism, 1e-9);
        $this->assertEqualsWithDelta(80.0, $derived->tradition, 1e-9);
        $this->assertEqualsWithDelta(75.0, $derived->restraint, 1e-9);
        $this->assertEquals($derived->vector(), Culture::tharados()->vector());
    }

    public function test_culture_nudges_dispositions_thrift_from_restraint(): void
    {
        $ascetic = new Culture('Ascetic', collectivism: 50, hierarchy: 50, tradition: 50, longTermOrientation: 50, restraint: 90, achievement: 50, piety: 50);
        $indulgent = new Culture('Indulgent', collectivism: 50, hierarchy: 50, tradition: 50, longTermOrientation: 50, restraint: 10, achievement: 50, piety: 50);

        $this->assertGreaterThan(0.0, $ascetic->traitModifier('thrift'));
        $this->assertLessThan(0.0, $indulgent->traitModifier('thrift'));
        $this->assertSame(0.0, $ascetic->traitModifier('agility')); // culture only nudges dispositions

        // The Tharadi culture supplies the +15 thrift nudge the region used to hand-set.
        $this->assertEqualsWithDelta(15.0, Culture::tharados()->traitModifier('thrift'), 1e-9);
        $this->assertEqualsWithDelta(0.0, RegionProfile::tharados()->traitModifier('thrift'), 1e-9);
    }

    public function test_an_ancestral_culture_biases_a_derived_one(): void
    {
        // Same materials, but an individualist ancestor pulls the derived culture away from pure derivation.
        $ancestor = new Culture('Trader', collectivism: 30, hierarchy: 30, tradition: 20, longTermOrientation: 30, restraint: 20, achievement: 80, piety: 20);
        $pure = Culture::fromMaterialConditions('Colony', scarcity: 1.0, volatility: 0.5);
        $inherited = Culture::fromMaterialConditions('Colony', scarcity: 1.0, volatility: 0.5, ancestral: $ancestor);

        // Inheritance drags collectivism down toward the trader ancestor (the two-way street).
        $this->assertLessThan($pure->collectivism, $inherited->collectivism);
        $this->assertGreaterThan($ancestor->collectivism, $inherited->collectivism);
    }

    public function test_baseline_cohesion_derives_from_collectivism(): void
    {
        $communal = new Culture('Communal', collectivism: 90, hierarchy: 50, tradition: 50, longTermOrientation: 50, restraint: 50, achievement: 50, piety: 50);
        $individualist = new Culture('Individualist', collectivism: 40, hierarchy: 50, tradition: 50, longTermOrientation: 50, restraint: 50, achievement: 50, piety: 50);

        $this->assertEqualsWithDelta(0.90, $communal->baselineCohesion(), 1e-9);
        $this->assertEqualsWithDelta(0.40, $individualist->baselineCohesion(), 1e-9);
        $this->assertGreaterThan($individualist->baselineCohesion(), $communal->baselineCohesion());
    }

    public function test_tharados_collectivism_sets_the_village_baseline_to_the_historical_value(): void
    {
        $village = new Village('Sunwell Oasis', 'Tharados', culture: Culture::tharados());

        // Tharados collectivism 85 → baseline 0.85, the value the engine ran on before the culture vector.
        $this->assertEqualsWithDelta(0.85, $village->baselineCohesion, 1e-9);
    }

    public function test_a_more_collectivist_culture_yields_higher_village_cohesion(): void
    {
        $communal = new Village('A', 'X', culture: new Culture('C', collectivism: 90, hierarchy: 50, tradition: 50, longTermOrientation: 50, restraint: 50, achievement: 50, piety: 50));
        $loose = new Village('B', 'X', culture: new Culture('L', collectivism: 50, hierarchy: 50, tradition: 50, longTermOrientation: 50, restraint: 50, achievement: 50, piety: 50));

        $this->assertGreaterThan($loose->cohesion(10), $communal->cohesion(10));
    }

    public function test_vector_exposes_all_seven_dimensions(): void
    {
        $vector = Culture::tharados()->vector();

        $this->assertSame(
            ['collectivism', 'hierarchy', 'tradition', 'longTermOrientation', 'restraint', 'achievement', 'piety'],
            array_keys($vector),
        );
    }
}
