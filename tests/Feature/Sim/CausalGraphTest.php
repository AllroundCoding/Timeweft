<?php

namespace Tests\Feature\Sim;

use App\Sim\Causality\CausalGraph;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-33: the events form a causal DAG, and the downstream-cone query returns
 * everything an edit to a given event would invalidate — the query ripple/undo
 * (M3 · Editing) is built on.
 */
class CausalGraphTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_downstream_cone_follows_the_chain(): void
    {
        // 1 → 2 → 3   (a union, a birth, then a death in childbirth)
        $graph = $this->graph([
            [1, []],
            [2, [1]],
            [3, [2]],
        ]);

        $this->assertSame([2, 3], $graph->downstreamCone(1));
        $this->assertSame([3], $graph->downstreamCone(2));
        $this->assertSame([], $graph->downstreamCone(3));
    }

    public function test_ancestors_walk_the_provenance_back_to_the_root(): void
    {
        $graph = $this->graph([
            [1, []],
            [2, [1]],
            [3, [2]],
        ]);

        $this->assertSame([1, 2], $graph->ancestors(3));
        $this->assertSame([], $graph->ancestors(1));
    }

    public function test_a_cone_gathers_every_branch_below_an_event(): void
    {
        // 1 fans out to 2 and 3; both feed 4 (a diamond).
        $graph = $this->graph([
            [1, []],
            [2, [1]],
            [3, [1]],
            [4, [2, 3]],
        ]);

        $this->assertSame([2, 3, 4], $graph->downstreamCone(1));
        $this->assertSame([1, 2, 3], $graph->ancestors(4)); // each ancestor counted once
        $this->assertSame([2, 3], $graph->directEffects(1));
    }

    public function test_an_unknown_event_has_an_empty_cone(): void
    {
        $graph = $this->graph([[1, []]]);

        $this->assertSame([], $graph->downstreamCone(999));
        $this->assertSame([], $graph->ancestors(999));
        $this->assertNull($graph->event(999));
    }

    public function test_a_seeded_run_is_an_acyclic_graph_whose_cones_resolve(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->advance(self::TICKS_PER_YEAR * 30);
        $graph = CausalGraph::of($world->chronicle);
        $events = $world->chronicle->all();
        $this->assertNotEmpty($events);

        foreach ($events as $event) {
            // No event lies in its own downstream cone — the timeline is acyclic.
            $this->assertNotContains($event->id, $graph->downstreamCone($event->id));
        }

        // A pairing's cone reaches the births (and beyond) it made possible.
        $pairing = $this->firstOfType($events, 'pairing');
        $birth = $this->firstOfType($events, 'birth');
        if ($pairing !== null && $birth !== null && $birth->causes === [$pairing->id]) {
            $this->assertContains($birth->id, $graph->downstreamCone($pairing->id));
        }

        // The cone resolves to real events, ascending.
        $cone = $graph->downstreamCone($events[0]->id);
        $resolved = $graph->events($cone);
        $this->assertCount(count($cone), $resolved);
    }

    /**
     * @param  list<array{0:int,1:list<int>}>  $nodes  [id, causes]
     */
    private function graph(array $nodes): CausalGraph
    {
        return new CausalGraph(array_map(
            static fn (array $n): ChronicleEvent => new ChronicleEvent($n[0], $n[0], 'test', "e{$n[0]}", [], $n[1]),
            $nodes,
        ));
    }

    /** @param list<ChronicleEvent> $events */
    private function firstOfType(array $events, string $type): ?ChronicleEvent
    {
        foreach ($events as $event) {
            if ($event->type === $type) {
                return $event;
            }
        }

        return null;
    }
}
