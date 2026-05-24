<?php

namespace Tests\Feature;

use App\Persistence\WorldStore;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * TWT-30: persist a world to the schema and reload it. The headline guarantee — a reloaded world
 * continues byte-identically to one that never stopped — is met by resuming from the stored checkpoint;
 * the relational rows are the queryable projection of the same world.
 */
class WorldStoreTest extends TestCase
{
    use RefreshDatabase;

    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_reloaded_world_continues_byte_identically(): void
    {
        $store = new WorldStore;

        $straight = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $straight->advance(self::TICKS_PER_YEAR * 8);

        // Save at year 5, reload from the database, and run the remaining 3 years.
        $source = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $source->advance(self::TICKS_PER_YEAR * 5);
        $id = $store->save($source)->id;
        $reloaded = $store->load($id);
        $reloaded->advance(self::TICKS_PER_YEAR * 3);

        $this->assertSame($straight->tick, $reloaded->tick);
        $this->assertSame($this->chronicle($straight), $this->chronicle($reloaded), 'the history matches a run that never stopped');
        $this->assertSame($this->roster($straight), $this->roster($reloaded), 'down to the same people');
    }

    public function test_the_relational_skeleton_is_persisted_for_inspection(): void
    {
        $store = new WorldStore;
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->advance(self::TICKS_PER_YEAR * 4);

        $record = $store->save($world);

        $this->assertSame($world->rng->seed(), $record->seed, 'the seed it replays from');
        $this->assertSame(count($world->villages), $record->villages()->count());
        $this->assertGreaterThan(0, $record->agents()->count(), 'its people are persisted as rows');
        $this->assertSame(count($world->chronicle->all()), $record->events()->count(), 'the whole timeline is persisted');
        $this->assertSame(1, $record->checkpoints()->count(), 'with one resume checkpoint');
    }

    public function test_a_folded_settlements_cohort_is_persisted_in_the_relational_projection(): void
    {
        $store = new WorldStore;
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);

        // A second settlement, folded by the LOD manager into its statistical stand-in (TWT-213):
        // its living souls become the cohort, and no living agent rows remain.
        $folded = $world->foundVillage('Granaria');
        $folded->foldIntoCohort($world->tick);
        $folded->cohortSickness = 12.5;
        $expectedPopulation = $folded->cohort?->population() ?? 0.0;

        $record = $store->save($world);

        $row = $record->villages()->where('name', 'Granaria')->firstOrFail();
        $this->assertNotNull($row->cohort, 'a folded settlement persists its cohort to the queryable rows, not only the checkpoint');
        $this->assertEqualsWithDelta($expectedPopulation, array_sum($row->cohort['byAge']), 0.001, 'its population survives the round-trip');
        $this->assertSame(12.5, $row->cohort['sickness'], 'as does its mean sickness');
        $this->assertSame(0, $row->agents()->count(), 'and it keeps no living agent rows — its souls live in the cohort');

        $tracked = $record->villages()->where('name', $world->villages[0]->name)->firstOrFail();
        $this->assertNull($tracked->cohort, 'a tracked, per-agent settlement has no cohort projection (null = tracked)');

        // The checkpoint half still carries it: a reloaded world resumes with the settlement folded.
        $reloaded = $store->load($record->id);
        $resumed = collect($reloaded->villages)->firstWhere('name', 'Granaria');
        $this->assertNotNull($resumed, 'the reloaded world still has the second settlement');
        $this->assertNotNull($resumed->cohort, 'resume restores the folded cohort from the checkpoint');
    }

    /** @return list<string> */
    private function chronicle(World $world): array
    {
        return array_map(static fn ($e): string => $e->text, $world->chronicle->all());
    }

    /** @return list<string> */
    private function roster(World $world): array
    {
        return array_map(
            static fn (Agent $a): string => sprintf('%d|%s|%s|%.1f', $a->id, $a->name, $a->sex, $a->trait('agility')),
            $world->village->livingAgents(),
        );
    }
}
