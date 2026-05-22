<?php

namespace App\Sim\Chronicle;

/**
 * One canonical event — a node in the timeline's causal graph (design doc 09).
 * Beyond its human-readable text it records *why* it happened: the prior events
 * it depended on (`causes`) and the typed conditions behind it (`factors`). This
 * provenance is what later lets an edit compute the downstream cone it invalidates.
 */
final class ChronicleEvent
{
    /**
     * @param  list<int>  $subjects  ids of the agents this event is about
     * @param  list<int>  $causes  ids of prior events that led to this one
     * @param  list<string>  $factors  typed non-event preconditions (e.g. 'famine', 'old-age', 'childbirth')
     */
    public function __construct(
        public readonly int $id,
        public readonly int $tick,
        public readonly string $type,
        public readonly string $text,
        public readonly array $subjects = [],
        public readonly array $causes = [],
        public readonly array $factors = [],
    ) {}

    /** @return array{id:int,tick:int,type:string,text:string,subjects:list<int>,causes:list<int>,factors:list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tick' => $this->tick,
            'type' => $this->type,
            'text' => $this->text,
            'subjects' => $this->subjects,
            'causes' => $this->causes,
            'factors' => $this->factors,
        ];
    }
}
