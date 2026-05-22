<?php

namespace App\Sim\Chronicle;

/**
 * The sparse, canonical record of notable events — the "skeleton" the timeline
 * is built from. Dense per-tick activity is texture and stays out of here.
 *
 * Each entry is a {@see ChronicleEvent} node carrying its causal provenance, so
 * the chronicle is not just a log but the timeline's causal graph (design doc 09).
 */
final class Chronicle
{
    /** @var list<ChronicleEvent> */
    private array $events = [];

    private int $nextId = 1;

    /**
     * @param  list<int>  $subjects  ids of the agents this event is about
     * @param  list<int>  $causes  ids of prior events that led to this one
     * @param  list<string>  $factors  typed non-event preconditions
     */
    public function record(
        int $tick,
        string $text,
        string $type = 'note',
        array $subjects = [],
        array $causes = [],
        array $factors = [],
    ): ChronicleEvent {
        $event = new ChronicleEvent($this->nextId++, $tick, $type, $text, $subjects, $causes, $factors);
        $this->events[] = $event;

        return $event;
    }

    /** @return list<ChronicleEvent> */
    public function all(): array
    {
        return $this->events;
    }

    public function last(): ?ChronicleEvent
    {
        return $this->events === [] ? null : $this->events[array_key_last($this->events)];
    }
}
