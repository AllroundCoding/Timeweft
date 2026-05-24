<?php

namespace App\Persistence;

use App\Models\EventDependency;
use App\Models\World as WorldRecord;
use App\Models\WorldCheckpoint;
use App\Sim\Direction\Milestone;
use App\Sim\Persistence\Checkpoint;
use App\Sim\World\Agent;
use App\Sim\World\Village;
use App\Sim\World\World;
use Illuminate\Support\Facades\DB;

/**
 * Saves a live {@see World} to the persistence schema (TWT-28) and reloads it (TWT-30) — the boundary
 * bridge between the pure in-memory engine and the database.
 *
 * Two layers, each for what it does best: the relational rows (worlds, villages, agents, institutions,
 * events, event_dependencies) are the *queryable, inspectable projection* that powers UI and
 * derive-on-demand reads; a {@see Checkpoint} stored alongside is the *exact-resume payload*. A reloaded
 * world continues byte-identically to one that never stopped (it replays from the checkpoint, TWT-32) —
 * the continuation bookkeeping (next id, festival latch) and the code-defined config the rows don't carry
 * ride in the checkpoint.
 */
final class WorldStore
{
    /** Persist a world: its skeleton as queryable rows, plus a checkpoint for exact resume. Returns the row. */
    public function save(World $world): WorldRecord
    {
        return DB::transaction(function () use ($world): WorldRecord {
            $record = WorldRecord::create([
                'seed' => $world->rng->seed(),
                'tick' => $world->tick,
                'relations' => $world->relations,
                'routes' => $world->routes,
                'milestones' => array_map($this->milestoneRow(...), $world->milestones),
            ]);

            foreach ($world->villages as $village) {
                $villageRow = $record->villages()->create($this->villageRow($village));
                foreach ($village->agents as $agent) {
                    $villageRow->agents()->create($this->agentRow($agent, $record->id));
                }
                if ($village->institution !== null) {
                    $record->institutions()->create([
                        'village_id' => $villageRow->id,
                        'name' => $village->institution->name,
                        'type' => $village->institution->type,
                        'founded_tick' => $village->institution->foundedTick,
                        'mandate' => $village->institution->mandate,
                        'effectiveness' => $village->institution->effectiveness,
                    ]);
                }
            }

            // Events first (capturing sim-id → row-id), then the provenance edges between them.
            $rowIdBySimId = [];
            foreach ($world->chronicle->all() as $event) {
                $rowIdBySimId[$event->id] = $record->events()->create([
                    'sim_id' => $event->id,
                    'tick' => $event->tick,
                    'type' => $event->type,
                    'text' => $event->text,
                    'subjects' => $event->subjects,
                    'factors' => $event->factors,
                ])->id;
            }
            foreach ($world->chronicle->all() as $event) {
                foreach ($event->causes as $causeSimId) {
                    if (isset($rowIdBySimId[$causeSimId])) {
                        EventDependency::create([
                            'event_id' => $rowIdBySimId[$event->id],
                            'cause_event_id' => $rowIdBySimId[$causeSimId],
                        ]);
                    }
                }
            }

            $record->checkpoints()->create([
                'tick' => $world->tick,
                'boundary_state' => serialize(Checkpoint::of($world)),
            ]);

            return $record;
        });
    }

    /** Reload a world, resumed from its latest checkpoint — byte-identical to one that never stopped. */
    public function load(int $worldId): World
    {
        $checkpoint = WorldCheckpoint::query()
            ->where('world_id', $worldId)
            ->orderByDesc('tick')
            ->firstOrFail();

        $resumed = unserialize($checkpoint->boundary_state);
        assert($resumed instanceof Checkpoint);

        return $resumed->resume();
    }

    /** @return array<string,mixed> */
    private function agentRow(Agent $agent, int $worldId): array
    {
        $needs = [];
        foreach ($agent->needs as $name => $need) {
            $needs[$name] = ['value' => $need->value, 'capacity' => 100.0]; // capacity is the implicit max until TWT-202
        }

        return [
            'world_id' => $worldId,
            'sim_id' => $agent->id,
            'name' => $agent->name,
            'species' => $agent->species,
            'sex' => $agent->sex,
            'birth_tick' => $agent->birthTick,
            'death_tick' => $agent->deathTick,
            'alive' => $agent->alive,
            'money' => $agent->stockpile->amount('money'),
            'profession' => $agent->profession,
            'traits' => $agent->traits,
            'needs' => $needs,
            'job_history' => $agent->jobHistory,
            'parent_ids' => $agent->parentIds,
        ];
    }

    /** @return array<string,mixed> */
    private function villageRow(Village $village): array
    {
        return [
            'name' => $village->name,
            'region' => $village->region,
            'x' => $village->x,
            'y' => $village->y,
            'land_yield' => $village->landYield,
            'technology' => $village->technology,
            'carrying_capacity' => $village->carryingCapacity,
            'culture' => $village->culture->vector(),
            'stockpile' => $village->stockpile->all(),
            'state' => [
                'inFamine' => $village->inFamine,
                'famineYears' => $village->famineYears,
                'collapsed' => $village->collapsed,
                'underpreparedYears' => $village->underpreparedYears,
                'starvationFactor' => $village->starvationFactor,
                'dietQuality' => $village->dietQuality,
                'mutualAid' => $village->mutualAid,
                'inOutbreak' => $village->inOutbreak,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function milestoneRow(Milestone $milestone): array
    {
        return [
            'name' => $milestone->name,
            'deadlineYear' => $milestone->deadlineYear,
            'prereqPopulation' => $milestone->prereqPopulation,
            'hard' => $milestone->hard,
            'achieved' => $milestone->achieved,
            'achievedTick' => $milestone->achievedTick,
            'wasForced' => $milestone->wasForced,
            'lapsed' => $milestone->lapsed,
        ];
    }
}
