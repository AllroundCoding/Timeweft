<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Culture\Faith;
use App\Sim\World\Village;
use PHPUnit\Framework\TestCase;

class FaithTest extends TestCase
{
    private function culture(float $collectivism = 50, float $hierarchy = 50, float $tradition = 50, float $piety = 50): Culture
    {
        return new Culture('C', collectivism: $collectivism, hierarchy: $hierarchy, tradition: $tradition, longTermOrientation: 50, restraint: 50, achievement: 50, piety: $piety);
    }

    public function test_binding_collectivist_hierarchical_culture_grows_a_binding_faith(): void
    {
        $tight = Faith::fromCulture('Tight', $this->culture(collectivism: 90, hierarchy: 85, tradition: 90, piety: 90));
        $loose = Faith::fromCulture('Loose', $this->culture(collectivism: 20, hierarchy: 15, tradition: 20, piety: 20));

        // Binding foundations rise with collectivism / hierarchy / tradition+piety.
        $this->assertGreaterThan($loose->loyalty, $tight->loyalty);
        $this->assertGreaterThan($loose->authority, $tight->authority);
        $this->assertGreaterThan($loose->sanctity, $tight->sanctity);
        // The individualizing foundation liberty moves the other way.
        $this->assertGreaterThan($tight->liberty, $loose->liberty);
    }

    public function test_piety_sets_how_strongly_the_faith_binds(): void
    {
        $this->assertEqualsWithDelta(0.9, Faith::fromCulture('A', $this->culture(piety: 90))->binding, 1e-9);
        $this->assertEqualsWithDelta(0.2, Faith::fromCulture('B', $this->culture(piety: 20))->binding, 1e-9);
    }

    public function test_tenets_are_the_most_weighted_foundations(): void
    {
        // A devout, traditional, hierarchical culture → sanctity/loyalty/authority on top.
        $faith = Faith::fromCulture('Devout', $this->culture(collectivism: 90, hierarchy: 80, tradition: 90, piety: 90));
        $tenets = $faith->tenets(3);

        $this->assertContains('sanctity', $tenets);
        $this->assertNotContains('liberty', $tenets); // the least-weighted foundation isn't a tenet
    }

    public function test_a_village_derives_its_faith_from_its_culture(): void
    {
        $village = new Village('Sunwell Oasis', 'Tharados', culture: Culture::tharados());
        $faith = $village->faith();

        $this->assertSame('the Way of Nara', $faith->name);
        // The Tharadi (collectivism 85, tradition/piety 80) hold a binding, sanctity-heavy faith.
        $this->assertGreaterThan($faith->liberty, $faith->sanctity);
    }
}
