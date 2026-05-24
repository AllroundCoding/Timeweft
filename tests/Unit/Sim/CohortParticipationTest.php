<?php

namespace Tests\Unit\Sim;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Cohort;
use App\Sim\World\ContagionEngine;
use App\Sim\World\MigrationEngine;
use App\Sim\World\TradeEngine;
use App\Sim\World\WarEngine;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-246 — a folded (cohort) settlement is no longer socially inert: it trades, migrates, wars, and
 * catches contagion with its neighbours at cohort granularity. (Byte-identical for all-tracked worlds
 * is pinned separately by SimulationDeterminismTest + the canonical hash gate.)
 */
class CohortParticipationTest extends TestCase
{
    private function world(): World
    {
        return World::seedTharadosVillage(new Rng('cohort-participation'), 8);
    }

    public function test_a_folded_settlement_exports_its_surplus_to_a_short_tracked_neighbour(): void
    {
        $world = $this->world();
        $primary = $world->villages[0];             // tracked, 8 souls, no stored food → importer
        $folded = $world->foundVillage('Granaria');
        $folded->cohort = Cohort::ofAdults(300.0);  // fold it: a large statistical settlement
        $folded->x = $primary->x;                   // co-located, so transit loss is ~nil
        $folded->y = $primary->y;
        $folded->stockpile->add('food', 6000.0);    // flush per cohort head → exporter

        TradeEngine::runDay($world, 0, TharadiCalendar::fromTick(0));

        $this->assertGreaterThan(0.0, $primary->stockpile->amount('food'), 'the folded settlement shipped food to its short neighbour');
        $this->assertLessThan(6000.0, $folded->stockpile->amount('food'), 'the folded settlement parted with surplus');
    }

    public function test_migration_from_a_folded_source_to_a_tracked_destination_conserves_population(): void
    {
        $world = $this->world();
        $destination = $world->villages[0];         // tracked, roomy
        $crowded = $world->foundVillage('Crowded');
        $crowded->cohort = Cohort::ofAdults(300.0);
        $crowded->carryingCapacity = 100;           // 3x over K → strong push
        $crowded->x = $destination->x;
        $crowded->y = $destination->y;

        $before = $crowded->headcount() + $destination->headcount();
        MigrationEngine::runDay($world, 0, TharadiCalendar::fromTick(0)); // year boundary — migration fires
        $after = $crowded->headcount() + $destination->headcount();

        $this->assertEqualsWithDelta($before, $after, 0.5, 'no souls lost or duplicated across the boundary');
        $this->assertGreaterThan(8, count($destination->livingAgents()), 'migrants materialized as tracked agents at the destination');
        $this->assertLessThan(300.0, $crowded->cohort?->population() ?? 0.0, 'the folded source shed migrants');
    }

    public function test_war_culls_a_folded_settlements_cohort(): void
    {
        $world = $this->world();
        $primary = $world->villages[0];
        $folded = $world->foundVillage('Hollow');
        $folded->cohort = Cohort::ofAdults(200.0);
        $world->relations[$primary->pairKey($folded)] = 0.1; // deep enmity → open war

        WarEngine::runDay($world, 0, TharadiCalendar::fromTick(0));

        $this->assertLessThan(200.0, $folded->cohort?->population() ?? 0.0, 'the folded side took war casualties');
    }

    public function test_contagion_reaches_a_folded_neighbour(): void
    {
        $world = $this->world();
        $primary = $world->villages[0];
        foreach ($primary->livingAgents() as $agent) {
            $need = $agent->needs['sickness'] ?? null;
            if ($need !== null) {
                $need->value = 90.0; // a raging outbreak at the tracked settlement
            }
        }
        $folded = $world->foundVillage('Nearby');
        $folded->cohort = Cohort::ofAdults(150.0);
        $folded->x = $primary->x + 50.0; // within the proximity contact range
        $folded->y = $primary->y;

        ContagionEngine::runDay($world, 0, TharadiCalendar::fromTick(0));

        $this->assertGreaterThan(0.0, $folded->cohortSickness, 'the folded settlement caught the contagion from its neighbour');
    }
}
