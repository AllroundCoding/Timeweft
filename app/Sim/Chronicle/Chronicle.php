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

    /** Start the event-id counter at a base — the per-region id block a decomposed sub-chronicle records into (TWT-112); default 1 is the normal single-stream world. */
    public function __construct(int $nextId = 1)
    {
        $this->nextId = $nextId;
    }

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

    /** The next event id this chronicle would assign — the base a decomposed sub-chronicle starts above (TWT-112). */
    public function nextId(): int
    {
        return $this->nextId;
    }

    /** Append an already-formed event — folds a decomposed region's epoch events back in, in a deterministic order (TWT-112). */
    public function append(ChronicleEvent $event): void
    {
        $this->events[] = $event;
    }
}
