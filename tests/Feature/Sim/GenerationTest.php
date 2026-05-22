<?php

namespace Tests\Feature\Sim;

use App\Sim\Direction\Generation;
use App\Sim\Direction\Milestone;
use App\Sim\Direction\Waypoint;
use App\Sim\Support\Rng;
use PHPUnit\Framework\TestCase;

/**
 * TWT-43: both generation modes on one engine — seed-forward ("surprise me")
 * and end-state-backward ("justify my Vaeris").
 */
class GenerationTest extends TestCase
{
    public function test_seed_forward_grows_a_world_from_initial_conditions(): void
    {
        $world = Generation::seedForward(new Rng('vaeris'), 8);

        $this->assertCount(8, $world->village->agents);
        $names = array_map(static fn (Milestone $m): string => $m->name, $world->milestones);
        $this->assertContains('trading post on the caravan road', $names, 'the default authored beat is present');
    }

    public function test_end_state_backward_pins_the_past_that_justifies_it(): void
    {
        $world = Generation::fromEndState(new Rng('vaeris'), 8, new Waypoint('empire', 500));

        $names = array_map(static fn (Milestone $m): string => $m->name, $world->milestones);
        $this->assertSame(['settlement', 'trading post', 'town', 'kingdom', 'empire'], $names);
        foreach ($world->milestones as $milestone) {
            $this->assertTrue($milestone->hard, 'authored waypoints are hard pins');
        }
    }

    public function test_several_end_states_fold_keeping_the_tightest_deadline(): void
    {
        // The empire requires a town by Year 370; also authoring a town by Year 300 should tighten it.
        $world = Generation::fromEndState(new Rng('vaeris'), 8, new Waypoint('empire', 500), new Waypoint('town', 300));

        $town = null;
        foreach ($world->milestones as $milestone) {
            if ($milestone->name === 'town') {
                $town = $milestone;
            }
        }
        $this->assertNotNull($town);
        $this->assertSame(300, $town->deadlineYear, 'the tighter authored deadline wins');
    }

    public function test_an_inconsistent_end_state_yields_no_world(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Generation::fromEndState(new Rng('vaeris'), 8, new Waypoint('empire', 50)); // too soon to be possible
    }
}
