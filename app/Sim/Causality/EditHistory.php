<?php

namespace App\Sim\Causality;

/**
 * Undo/redo over the edit log (design doc 09). The {@see EditLog} is the
 * append-only authoring record; this is the navigation *cursor* across it.
 *
 * - **Linear undo/redo** walks the most-recent edits: undo tombstones the last
 *   active edit, redo lifts it back; recording a new edit forks history and
 *   clears the redo stack (standard editor semantics).
 * - **Selective undo** ("rebase") tombstones *any* past edit while keeping the
 *   later ones — trivial here because edits are independent suppression rules
 *   the log folds together, so dropping one in the middle just leaves a hole.
 *
 * Either way the resulting world is recomputed by replaying with the log's
 * active edits ({@see RetroactiveRipple::canonicalTimeline}). Recompute is a
 * full re-run for now; cone-limited recompute awaits checkpoints (TWT-32) and
 * forked sub-streams (TWT-107).
 */
final class EditHistory
{
    /** @var list<int> ids tombstoned by undo(), awaiting redo() — newest last */
    private array $redoStack = [];

    public function __construct(private readonly EditLog $log = new EditLog) {}

    public function log(): EditLog
    {
        return $this->log;
    }

    /** Record a new edit. A fresh edit forks history, so the redo stack is dropped. */
    public function apply(string $note, Intervention $intervention): Edit
    {
        $this->redoStack = [];

        return $this->log->record($note, $intervention);
    }

    /** Linear undo: tombstone the most-recent active edit. Returns it, or null if there's nothing to undo. */
    public function undo(): ?Edit
    {
        $active = $this->log->active();
        if ($active === []) {
            return null;
        }
        $edit = $active[array_key_last($active)];
        $this->log->retract($edit->id);
        $this->redoStack[] = $edit->id;

        return $edit;
    }

    /** Linear redo: restore the most-recently-undone edit. Returns it, or null if there's nothing to redo. */
    public function redo(): ?Edit
    {
        if ($this->redoStack === []) {
            return null;
        }
        $id = array_pop($this->redoStack);
        $this->log->restore($id);

        return $this->log->find($id);
    }

    public function canUndo(): bool
    {
        return $this->log->active() !== [];
    }

    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }

    /** Selective undo (rebase): tombstone one past edit, keeping the later ones in force. */
    public function undoEdit(int $id): void
    {
        $this->log->retract($id);
    }

    /** Bring a selectively-undone edit back. */
    public function restoreEdit(int $id): void
    {
        $this->log->restore($id);
    }
}
