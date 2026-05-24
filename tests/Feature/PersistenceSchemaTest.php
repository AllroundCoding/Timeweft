<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Event;
use App\Models\Village;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TWT-28: the persistence schema (design doc 10) stands up and round-trips — a world graph (settlements,
 * people, institutions, the event timeline + its provenance edges) saves and reloads, JSONB bags survive,
 * the living/dead distinction is nullable, and deleting a world cascades. Portable: jsonb maps to text on
 * sqlite (tested here) and to real jsonb on Postgres.
 */
class PersistenceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_world_graph_persists_and_reloads_with_relationships_and_json_casts(): void
    {
        $world = World::create([
            'name' => 'Vaeris',
            'seed' => 123_456,
            'tick' => 5_760,
            'relations' => ['Breadbasket↔Dusthold' => 0.4],
            'routes' => ['Breadbasket↔Dusthold' => ['ageYears' => 3, 'lastYear' => 2]],
            'milestones' => [['name' => 'trading post', 'achieved' => true]],
        ]);
        $village = $world->villages()->create([
            'name' => 'Sunwell Oasis', 'region' => 'Tharados', 'land_yield' => 22.0, 'carrying_capacity' => 22,
            'culture' => ['collectivism' => 60.0], 'stockpile' => ['food' => 120.0, 'money' => 40.0], 'state' => ['inFamine' => false],
        ]);
        $village->agents()->create([
            'world_id' => $world->id, 'sim_id' => 1, 'name' => 'Vaer', 'species' => 'Vulpini', 'sex' => 'f',
            'birth_tick' => -30 * 5_760, 'death_tick' => null, 'money' => 12.5, 'profession' => 'farming',
            'traits' => ['agility' => 80.0, 'furColor' => 'red'],
            'needs' => ['hunger' => ['value' => 20.0, 'capacity' => 100.0]],
            'job_history' => ['farming' => 30], 'parent_ids' => [],
        ]);
        $world->institutions()->create([
            'village_id' => $village->id, 'name' => 'Temple of Nara', 'type' => 'temple',
            'founded_tick' => 9 * 5_760, 'mandate' => 0.55, 'effectiveness' => 0.9,
        ]);
        $birth = $world->events()->create(['sim_id' => 1, 'tick' => 0, 'type' => 'founding', 'text' => 'Sunwell is founded']);
        $death = $world->events()->create(['sim_id' => 2, 'tick' => 100, 'type' => 'death', 'text' => 'Vaer dies', 'subjects' => [1], 'factors' => ['old-age']]);
        $death->dependencies()->create(['cause_event_id' => $birth->id]);

        // Reload entirely from the database — nothing from the in-memory objects.
        $loaded = World::with(['villages.agents', 'institutions', 'events.dependencies'])->findOrFail($world->id);

        $this->assertSame(123_456, $loaded->seed, 'the seed it replays from');
        $this->assertSame(0.4, $loaded->relations['Breadbasket↔Dusthold'], 'the relations ledger round-trips through JSONB');
        $this->assertSame(3, $loaded->routes['Breadbasket↔Dusthold']['ageYears']);

        $agent = $loaded->villages->firstOrFail()->agents->firstOrFail();
        $this->assertSame('Vulpini', $agent->species);
        $this->assertNull($agent->death_tick, 'a living agent has no death tick (nullable)');
        $this->assertTrue($agent->alive);
        // JSON decodes a whole-number float (100.0) back as int 100; the sim's float-typed Need coerces it.
        $this->assertSame(['value', 'capacity'], array_keys($agent->needs['hunger']), 'needs keep their {value, capacity} shape');
        $this->assertEqualsWithDelta(100.0, $agent->needs['hunger']['capacity'], 1e-9);
        $this->assertSame('red', $agent->traits['furColor']);

        $deathEvent = $loaded->events->firstWhere('type', 'death');
        $this->assertNotNull($deathEvent);
        $this->assertSame($birth->id, $deathEvent->dependencies->firstOrFail()->cause_event_id, 'the causal edge points back to the cause');
    }

    public function test_deleting_a_world_cascades_to_its_entities(): void
    {
        $world = World::create(['seed' => 1, 'tick' => 0]);
        $village = $world->villages()->create(['name' => 'X', 'region' => 'R', 'land_yield' => 10.0, 'culture' => []]);
        $village->agents()->create(['world_id' => $world->id, 'sim_id' => 1, 'name' => 'A', 'species' => 'Vulpini', 'sex' => 'm', 'birth_tick' => 0, 'traits' => [], 'needs' => []]);
        $world->events()->create(['sim_id' => 1, 'tick' => 0, 'type' => 'note', 'text' => 'x']);

        $world->delete();

        $this->assertSame(0, Agent::count(), 'agents are removed with their world');
        $this->assertSame(0, Village::count());
        $this->assertSame(0, Event::count());
    }
}
