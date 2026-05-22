<?php

namespace App\Sim\Causality;

use App\Sim\Chronicle\Chronicle;
use App\Sim\Chronicle\ChronicleEvent;

/**
 * The timeline's events as a directed acyclic graph, read from the provenance
 * each event records (its `causes`, TWT-27). Edges run cause → effect, so the
 * graph answers the question every editing operation needs (design doc 09):
 *
 *   given an event, what is its *downstream cone* — everything causally
 *   affected by it, and therefore everything an edit to it would invalidate.
 *
 * Pure and order-stable: built once from a snapshot of the chronicle, every
 * query returns ids in ascending order so results are deterministic.
 */
final class CausalGraph
{
    /** @var array<int,ChronicleEvent> */
    private array $byId = [];

    /** @var array<int,list<int>> event id → ids of the events it directly caused */
    private array $effects = [];

    /** @param list<ChronicleEvent> $events */
    public function __construct(array $events)
    {
        foreach ($events as $event) {
            $this->byId[$event->id] = $event;
            $this->effects[$event->id] ??= [];
        }
        foreach ($events as $event) {
            foreach ($event->causes as $causeId) {
                $this->effects[$causeId][] = $event->id;
            }
        }
    }

    public static function of(Chronicle $chronicle): self
    {
        return new self($chronicle->all());
    }

    /**
     * Every event transitively caused by $eventId — the cone an edit to it would invalidate.
     * Excludes the event itself; returns ids ascending.
     *
     * @return list<int>
     */
    public function downstreamCone(int $eventId): array
    {
        return $this->reach($this->effects[$eventId] ?? [], fn (int $id): array => $this->effects[$id] ?? []);
    }

    /**
     * Every event $eventId transitively depends on — its provenance chain (ancestors).
     * Excludes the event itself; returns ids ascending.
     *
     * @return list<int>
     */
    public function ancestors(int $eventId): array
    {
        return $this->reach($this->causesOf($eventId), fn (int $id): array => $this->causesOf($id));
    }

    /**
     * The events directly caused by $eventId (one hop downstream).
     *
     * @return list<int>
     */
    public function directEffects(int $eventId): array
    {
        $ids = $this->effects[$eventId] ?? [];
        sort($ids);

        return $ids;
    }

    public function event(int $eventId): ?ChronicleEvent
    {
        return $this->byId[$eventId] ?? null;
    }

    /**
     * Resolve a set of event ids to their events, dropping any unknown ids, in ascending id order.
     *
     * @param  list<int>  $ids
     * @return list<ChronicleEvent>
     */
    public function events(array $ids): array
    {
        sort($ids);

        return array_values(array_filter(array_map(fn (int $id): ?ChronicleEvent => $this->byId[$id] ?? null, $ids)));
    }

    /**
     * Breadth-first reachability from a frontier, following $next; ids ascending, each visited once.
     *
     * @param  list<int>  $frontier
     * @param  callable(int):list<int>  $next
     * @return list<int>
     */
    private function reach(array $frontier, callable $next): array
    {
        $seen = [];
        $stack = $frontier;
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($next($id) as $adjacent) {
                if (! isset($seen[$adjacent])) {
                    $stack[] = $adjacent;
                }
            }
        }
        $ids = array_keys($seen);
        sort($ids);

        return $ids;
    }

    /** @return list<int> */
    private function causesOf(int $eventId): array
    {
        return $this->byId[$eventId]->causes ?? [];
    }
}
