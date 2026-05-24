<?php

namespace Tests\Unit\Sim;

use App\Sim\Support\Rng;
use App\Sim\World\Cohort;
use App\Sim\World\LodManager;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

class LodManagerTest extends TestCase
{
    private function world(): World
    {
        return World::seedTharadosVillage(new Rng('lod'), 8);
    }

    public function test_a_small_settlement_stays_tracked_after_reconcile(): void
    {
        $world = $this->world();

        LodManager::reconcile($world, $world->tick);

        // Below the threshold and (as the focus) salient — untouched, so the canonical run stays byte-identical.
        $this->assertTrue($world->villages[0]->isTracked());
    }

    public function test_an_oversized_non_focus_settlement_folds_and_conserves_population(): void
    {
        $world = $this->world();
        $big = $world->foundVillage('Bigton', population: LodManager::COHORT_THRESHOLD + 50);
        $living = count($big->livingAgents());

        LodManager::reconcile($world, $world->tick);

        $this->assertTrue($world->villages[0]->isTracked(), 'the focus settlement stays tracked');
        $this->assertFalse($big->isTracked(), 'the oversized non-focus settlement folds');
        $this->assertSame([], $big->livingAgents(), 'folded: no individuals to simulate');
        $this->assertEqualsWithDelta($living, $big->cohort?->population() ?? 0.0, 0.001, 'population conserved across the fold');
    }

    public function test_a_folded_cohort_advances_without_materializing_individuals(): void
    {
        // A 50,000-soul settlement carried as a cohort — the scaling case.
        $metropolis = new Village('Metropolis', 'Tharados', [], landYield: 10_000.0);
        $metropolis->cohort = Cohort::ofAdults(50_000.0);

        for ($year = 0; $year < 50; $year++) {
            LodManager::advanceYear($metropolis);
        }

        // 50 years advanced for 50k souls touching only ~90 age bands — never an individual loop.
        $this->assertFalse($metropolis->isTracked());
        $this->assertSame([], $metropolis->livingAgents());
        $this->assertGreaterThan(0.0, $metropolis->cohort?->population() ?? 0.0);
    }

    public function test_materialize_round_trips_and_conserves_population(): void
    {
        $world = $this->world();
        $big = $world->foundVillage('Bigton', population: LodManager::COHORT_THRESHOLD + 50);
        LodManager::reconcile($world, $world->tick);
        $folded = $big->cohort?->population() ?? 0.0;

        $world->materialize($big);

        $this->assertTrue($big->isTracked(), 'materialized back to tracked');
        $this->assertEqualsWithDelta($folded, count($big->livingAgents()), 1.0, 'population conserved across promotion');
    }

    public function test_a_folded_settlement_promotes_when_attention_arrives(): void
    {
        $world = $this->world();
        $big = $world->foundVillage('Bigton', population: LodManager::COHORT_THRESHOLD + 50);

        LodManager::reconcile($world, $world->tick); // unattended + oversized → folds
        $this->assertFalse($big->isTracked(), 'oversized and unattended, it folds');
        $folded = $big->cohort?->population() ?? 0.0;

        $world->setSalient('Bigton');                // the camera moves to it
        LodManager::reconcile($world, $world->tick); // attention arrives → promotes

        $this->assertTrue($big->isTracked(), 'attention materializes the cohort back into individuals');
        $this->assertEqualsWithDelta($folded, count($big->livingAgents()), 1.0, 'population conserved across promotion');
    }

    public function test_attention_leaving_refolds_an_oversized_settlement(): void
    {
        $world = $this->world();
        $big = $world->foundVillage('Bigton', population: LodManager::COHORT_THRESHOLD + 50);

        $world->setSalient('Bigton');
        LodManager::reconcile($world, $world->tick);
        $this->assertTrue($big->isTracked(), 'a salient settlement stays tracked even when oversized');

        $world->setSalient();                        // attention leaves
        LodManager::reconcile($world, $world->tick);
        $this->assertFalse($big->isTracked(), 'once unattended, the oversized settlement folds again');
    }
}
