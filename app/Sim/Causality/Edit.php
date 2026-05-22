<?php

namespace App\Sim\Causality;

/**
 * One entry in the author's edit log — a recorded, reversible change to the
 * past (design doc 09). An edit is never hard-deleted; undoing it sets a
 * **tombstone** (`retracted`) so the authoring history stays auditable and the
 * change can be brought back. The edit itself is the {@see Intervention} the
 * world is replayed with.
 */
final class Edit
{
    public function __construct(
        public readonly int $id,
        public readonly string $note,
        public readonly Intervention $intervention,
        public readonly bool $retracted = false,
    ) {}

    public function withRetracted(bool $retracted): self
    {
        return new self($this->id, $this->note, $this->intervention, $retracted);
    }
}
