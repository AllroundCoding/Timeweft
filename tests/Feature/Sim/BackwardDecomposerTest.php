<?php

namespace Tests\Feature\Sim;

use App\Sim\Direction\BackwardDecomposer;
use App\Sim\Direction\Milestone;
use App\Sim\Direction\Waypoint;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-41: backward generation. From an authored end-state, decompose into the
 * ordered constraint graph of preconditions that justify it, and let the
 * forward director realize that past in order.
 */
class BackwardDecomposerTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_decomposes_an_end_state_into_an_ordered_constraint_graph(): void
    {
        $graph = BackwardDecomposer::decompose(new Waypoint('empire', 500));

        // earliest precondition first, the end-state last
        $this->assertSame(
            ['settlement', 'trading post', 'town', 'kingdom', 'empire'],
            array_map(static fn (Milestone $m): string => $m->name, $graph),
        );

        $byName = [];
        foreach ($graph as $m) {
            $byName[$m->name] = $m;
        }

        // each waypoint is back-dated a lead-time before the beat it enables
        $this->assertSame(500, $byName['empire']->deadlineYear);
        $this->assertSame(420, $byName['kingdom']->deadlineYear);
        $this->assertSame(370, $byName['town']->deadlineYear);
        $this->assertSame(345, $byName['trading post']->deadlineYear);
        $this->assertSame(335, $byName['settlement']->deadlineYear);

        // the precondition edges, and authored facts are hard pins
        $this->assertSame(['kingdom'], $byName['empire']->prerequisites);
        $this->assertSame(['settlement'], $byName['trading post']->prerequisites);
        $this->assertSame([], $byName['settlement']->prerequisites);
        $this->assertTrue($byName['empire']->hard);
    }

    public function test_an_end_state_too_soon_is_unsatisfiable(): void
    {
        $this->assertTrue(BackwardDecomposer::isSatisfiable(new Waypoint('empire', 500)));
        // An empire by Year 50 would push its settlement before the world begins — impossible.
        $this->assertFalse(BackwardDecomposer::isSatisfiable(new Waypoint('empire', 50)));
    }

    public function test_a_decomposed_arc_unfolds_in_order_through_the_director(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->milestones = BackwardDecomposer::decompose(new Waypoint('town', 40));
        $world->advance(self::TICKS_PER_YEAR * 40);

        $settlement = $this->milestoneTick($world, 'settlement');
        $post = $this->milestoneTick($world, 'trading post');
        $town = $this->milestoneTick($world, 'town');

        $this->assertNotNull($settlement, 'the settlement waypoint is reached');
        $this->assertNotNull($post, 'the trading-post waypoint is reached');
        $this->assertNotNull($town, 'the town end-state is reached');

        // the past unfolds in the order the decomposition demanded
        $this->assertLessThan($post, $settlement);
        $this->assertLessThan($town, $post);
    }

    private function milestoneTick(World $world, string $name): ?int
    {
        foreach ($world->chronicle->all() as $event) {
            if ($event->type === 'milestone' && str_contains($event->text, $name)) {
                return $event->tick;
            }
        }

        return null;
    }
}
