<?php

namespace Tests\Unit\Sim;

use App\Sim\Culture\Culture;
use App\Sim\Support\Rng;
use App\Sim\World\RelationsEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-52: cohesion as a computed value, per edge between settlements — the inter-settlement dimension
 * that complements the within-settlement size-decay (TWT-10). Two settlements cooperate readily when
 * allied and kindred, and hardly at all when rivals; kinship scales how smoothly amity translates to
 * cooperation. Design doc 07.
 */
class CohesionTest extends TestCase
{
    public function test_allies_cohere_and_rivals_do_not(): void
    {
        $world = new World(new Rng('coh'));
        $a = new Village('Aaa', 'Tharados');
        $b = new Village('Bbb', 'Tharados'); // same culture → kindred
        $world->villages = [$a, $b];

        $world->relations[$this->edge($a, $b)] = 0.9; // allied
        $allied = RelationsEngine::cohesion($world, $a, $b);
        $world->relations[$this->edge($a, $b)] = 0.05; // sworn enemies
        $rival = RelationsEngine::cohesion($world, $a, $b);

        $this->assertGreaterThan(0.7, $allied, 'allied kin cohere strongly');
        $this->assertLessThan(0.1, $rival, 'rivals hardly cohere');
        $this->assertGreaterThan($rival, $allied);
    }

    public function test_kinship_scales_cohesion_at_equal_standing(): void
    {
        $world = new World(new Rng('coh'));
        $home = new Village('Aaa', 'Tharados');
        $kin = new Village('Bbb', 'Tharados'); // same culture
        $foreign = new Village('Ccc', 'Tharados', culture: new Culture('Plain', collectivism: 90, hierarchy: 10, tradition: 10, longTermOrientation: 90, restraint: 10, achievement: 90, piety: 10));
        $world->villages = [$home, $kin, $foreign];

        $world->relations[$this->edge($home, $kin)] = 0.8;
        $world->relations[$this->edge($home, $foreign)] = 0.8; // identical standing

        $this->assertGreaterThan(
            RelationsEngine::cohesion($world, $home, $foreign),
            RelationsEngine::cohesion($world, $home, $kin),
            'kindred peoples cooperate more smoothly than strangers at the same standing',
        );
    }

    public function test_a_pair_without_history_coheres_at_the_neutral_standing(): void
    {
        $world = new World(new Rng('coh'));
        $a = new Village('Aaa', 'Tharados');
        $b = new Village('Bbb', 'Tharados');
        $world->villages = [$a, $b];

        $this->assertEqualsWithDelta(0.5, RelationsEngine::cohesion($world, $a, $b), 1e-9, 'kindred strangers cohere at neutral, neither allied nor rival');
    }

    private function edge(Village $a, Village $b): string
    {
        return $a->name < $b->name ? "{$a->name}↔{$b->name}" : "{$b->name}↔{$a->name}";
    }
}
