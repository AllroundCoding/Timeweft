<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\World\Village;
use PHPUnit\Framework\TestCase;

class CultureTest extends TestCase
{
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
