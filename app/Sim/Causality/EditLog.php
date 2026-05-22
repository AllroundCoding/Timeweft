<?php

namespace App\Sim\Causality;

/**
 * The author's append-only edit history — the second of the two histories
 * (design doc 09), alongside the in-world canonical timeline. Edits are
 * recorded in order and never removed; undoing one **tombstones** it (marks it
 * retracted) so the past stays auditable and reversible. The active edits fold
 * into a single {@see Intervention} the world is replayed with.
 *
 * This is the substrate undo/redo (TWT-36) is built on.
 */
final class EditLog
{
    /** @var list<Edit> */
    private array $edits = [];

    private int $nextId = 1;

    public function record(string $note, Intervention $intervention): Edit
    {
        $edit = new Edit($this->nextId++, $note, $intervention);
        $this->edits[] = $edit;

        return $edit;
    }

    /** Tombstone an edit — mark it retracted, never remove it; the authoring history stays whole. */
    public function retract(int $id): void
    {
        $this->setRetracted($id, true);
    }

    /** Lift an edit's tombstone — bring a retracted edit back into force. */
    public function restore(int $id): void
    {
        $this->setRetracted($id, false);
    }

    /** @return list<Edit> the full authoring history, tombstones included */
    public function all(): array
    {
        return $this->edits;
    }

    /** @return list<Edit> the edits currently in force */
    public function active(): array
    {
        return array_values(array_filter($this->edits, static fn (Edit $e): bool => ! $e->retracted));
    }

    /** The active edits folded into one intervention to replay the world with. */
    public function asIntervention(): Intervention
    {
        return Intervention::anyOf(...array_map(static fn (Edit $e): Intervention => $e->intervention, $this->active()));
    }

    private function setRetracted(int $id, bool $retracted): void
    {
        foreach ($this->edits as $i => $edit) {
            if ($edit->id === $id) {
                $this->edits[$i] = $edit->withRetracted($retracted);

                return;
            }
        }
    }
}
