<?php

namespace App\Http\Timeline;

use App\Sim\Direction\Milestone;
use App\Sim\Engine;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;

/**
 * Projects the engine's canonical output into the gantt timeline's view model (TWT-54).
 *
 * A pure boundary transform over the {@see Engine} query surface: it shapes the chronicle and the
 * full cast into life-span rows, per-life event markers, a world-events lane, milestone pins, and
 * the time axis. Positions stay in raw ticks — pixel layout is the client's job — and every
 * collection is ordered by a stable key, so the same run always projects identically.
 */
final class TimelineProjection
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    /**
     * @return array{
     *     axis: array{ticksPerYear:int,startTick:int,endTick:int,startYear:int,endYear:int},
     *     lives: list<array{id:int,name:string,sex:string,species:string,profession:string|null,birthTick:int,deathTick:int|null,alive:bool,parentIds:list<int>,partnerId:int|null,events:list<array{tick:int,type:string,text:string}>}>,
     *     world: list<array{tick:int,type:string,text:string}>,
     *     milestones: list<array{name:string,achievedTick:int|null,deadlineTick:int,achieved:bool,lapsed:bool,hard:bool,forced:bool}>,
     *     counts: array{total:int,living:int,died:int,born:int}
     * }
     */
    public static function from(Engine $engine): array
    {
        $now = $engine->tick();

        // Bucket events by subject so each life carries its own beats; subject-less beats are world-scale.
        /** @var array<int,list<array{tick:int,type:string,text:string}>> $byAgent */
        $byAgent = [];
        $world = [];
        foreach ($engine->chronicle() as $event) {
            $row = ['tick' => $event->tick, 'type' => $event->type, 'text' => $event->text];
            if ($event->subjects === []) {
                $world[] = $row;

                continue;
            }
            foreach ($event->subjects as $subjectId) {
                $byAgent[$subjectId][] = $row;
            }
        }

        $agents = $engine->agents();
        // Oldest lives first, ties broken by id — a stable order so the same run lays out identically.
        usort($agents, static fn (Agent $a, Agent $b): int => [$a->birthTick, $a->id] <=> [$b->birthTick, $b->id]);

        $living = 0;
        $born = 0;
        $lives = [];
        foreach ($agents as $agent) {
            if ($agent->alive) {
                $living++;
            }
            if ($agent->parentIds !== []) {
                $born++;
            }
            $lives[] = [
                'id' => $agent->id,
                'name' => $agent->name,
                'sex' => $agent->sex,
                'species' => $agent->species,
                'profession' => $agent->profession,
                'birthTick' => $agent->birthTick,
                'deathTick' => $agent->deathTick,
                'alive' => $agent->alive,
                'parentIds' => array_values($agent->parentIds),
                'partnerId' => $agent->partnerId,
                'events' => $byAgent[$agent->id] ?? [],
            ];
        }

        return [
            'axis' => [
                'ticksPerYear' => self::TICKS_PER_YEAR,
                'startTick' => 0,
                'endTick' => $now,
                'startYear' => TharadiCalendar::fromTick(0)->year,
                'endYear' => TharadiCalendar::fromTick($now)->year,
            ],
            'lives' => $lives,
            'world' => $world,
            'milestones' => array_map(static fn (Milestone $m): array => [
                'name' => $m->name,
                'achievedTick' => $m->achievedTick,
                'deadlineTick' => $m->deadlineYear * self::TICKS_PER_YEAR,
                'achieved' => $m->achieved,
                'lapsed' => $m->lapsed,
                'hard' => $m->hard,
                'forced' => $m->wasForced,
            ], $engine->milestones()),
            'counts' => [
                'total' => count($agents),
                'living' => $living,
                'died' => count($agents) - $living,
                'born' => $born,
            ],
        ];
    }
}
