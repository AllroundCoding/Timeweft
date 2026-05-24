<?php

namespace Tests\Unit\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Direction\Milestone;
use App\Sim\Engine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-88: the engine API surface — seed · advance · query · steer. A faithful façade over the raw engine
 * (the same seed grows the same world), with cheap derive-on-demand queries of any past tick.
 */
class EngineTest extends TestCase
{
    private const YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_seeding_then_advancing_matches_the_raw_engine(): void
    {
        $ticks = self::YEAR * 8;
        $engine = Engine::seed('vaeris', 8)->advance($ticks);

        $raw = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $raw->advance($ticks);

        $this->assertSame($raw->tick, $engine->tick());
        $this->assertSame($this->texts($raw->chronicle->all()), $this->texts($engine->chronicle()), 'the façade runs the same world as the raw engine');
        $this->assertSame($this->roster($raw->village->livingAgents()), $this->roster($engine->livingAgents()), 'down to the same people');
    }

    public function test_query_surfaces_the_world(): void
    {
        $engine = Engine::seed('vaeris', 8)->advance(self::YEAR * 3);

        $this->assertSame(self::YEAR * 3, $engine->tick());
        $this->assertNotEmpty($engine->chronicle());
        $this->assertGreaterThan(0, $engine->population());
    }

    public function test_querying_a_past_tick_reconstructs_it_faithfully(): void
    {
        $engine = Engine::seed('vaeris', 8)->advance(self::YEAR * 8);

        $past = $engine->at(self::YEAR * 5);

        $straight = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $straight->advance(self::YEAR * 5);

        $this->assertSame(self::YEAR * 5, $past->tick, 'lands exactly on the queried tick');
        $this->assertSame($this->texts($straight->chronicle->all()), $this->texts($past->chronicle->all()), 'derive-on-demand reconstructs the past faithfully');
    }

    public function test_pinning_registers_a_beat_to_steer_toward(): void
    {
        $engine = Engine::seed('vaeris', 8);
        $beat = new Milestone('a great library', 20, 30);

        $engine->pin($beat);

        $this->assertContains($beat, $engine->world()->milestones, 'the pinned beat is registered for the director to steer toward');
    }

    public function test_it_is_deterministic(): void
    {
        $a = Engine::seed('vaeris', 8)->advance(self::YEAR * 6);
        $b = Engine::seed('vaeris', 8)->advance(self::YEAR * 6);

        $this->assertSame($this->texts($a->chronicle()), $this->texts($b->chronicle()), 'same seed → same world');
    }

    /**
     * @param  list<ChronicleEvent>  $events
     * @return list<string>
     */
    private function texts(array $events): array
    {
        return array_map(static fn (ChronicleEvent $e): string => $e->text, $events);
    }

    /**
     * @param  list<Agent>  $agents
     * @return list<string>
     */
    private function roster(array $agents): array
    {
        $rows = array_map(static fn (Agent $a): string => sprintf('%d|%s|%s|%.1f', $a->id, $a->name, $a->sex, $a->trait('agility')), $agents);
        sort($rows);

        return $rows;
    }
}
