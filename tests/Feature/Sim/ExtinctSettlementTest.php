<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-253: a settlement that has lost its last inhabitant is mourned once and then simulated no more —
 * no harvests, blights, or land/tech beats fire over a graveyard. The per-settlement day engines are
 * gated on living population at the run loop; the world-level engines still run, so in-migration could
 * in principle repopulate it.
 */
class ExtinctSettlementTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_an_extinct_settlement_stops_emitting_economy_and_shock_events(): void
    {
        $world = World::seedTharadosVillage(new Rng('graveyard'), 8);

        // The last of its people are gone — the oasis is a graveyard from the outset.
        foreach ($world->village->agents as $agent) {
            $agent->alive = false;
            $agent->deathTick = 0;
        }
        $this->assertSame([], $world->village->livingAgents(), 'precondition: the settlement is extinct');

        $world->advance(self::TICKS_PER_YEAR * 8); // eight years pass over the empty oasis

        $types = [];
        foreach ($world->chronicle->all() as $event) {
            $types[$event->type] = ($types[$event->type] ?? 0) + 1;
        }

        // The emptied settlement is mourned — exactly once.
        $this->assertArrayHasKey('collapse', $types, 'the emptied settlement is mourned');
        $this->assertSame(1, $types['collapse'], 'and only once');

        // ...and then nothing economic or catastrophic is chronicled over the graveyard (the bug: a
        // dead settlement that kept having bountiful harvests and blights for decades).
        foreach (['harvest-lean', 'harvest-bumper', 'shock-blight', 'tech-advance', 'land-exhausted', 'land-recovered'] as $type) {
            $this->assertArrayNotHasKey($type, $types, "an extinct settlement emitted a '{$type}' event");
        }
    }
}
