<?php

namespace App\Sim\Persistence;

use App\Sim\World\World;
use RuntimeException;

/**
 * Derive-on-demand reconstruction (design docs 01/04; TWT-38) — the "materialize on observation" half of
 * the architecture. Rather than holding every tick of every life in memory, keep a sparse set of
 * {@see Checkpoint}s and recompute the dense texture of any tick only when someone looks: resume the
 * nearest checkpoint at or before the target and replay deterministically forward to it.
 *
 * Replay is exact (the forked-sub-stream seed property, TWT-107/32), so the world this hands back at a
 * tick is byte-identical to one that ran straight there — its agents carry the activity and need values
 * they held at that very tick. Storage grows with *attention*, not time × population: a denser
 * scattering of checkpoints buys cheaper reconstruction, a sparser one cheaper storage.
 */
final class Timeline
{
    /** @var array<int,Checkpoint> tick => the checkpoint anchored there */
    private array $checkpoints = [];

    /** Anchor a checkpoint so later reconstruction can replay forward from it. */
    public function anchor(Checkpoint $checkpoint): void
    {
        $this->checkpoints[$checkpoint->tick] = $checkpoint;
    }

    /** Take and anchor a checkpoint of the world at its current tick. */
    public function record(World $world): void
    {
        $this->anchor($world->checkpoint());
    }

    /**
     * The world as it stood at $tick — replayed from the nearest checkpoint at or before it. The returned
     * world is a fresh, independent copy; reconstructing again yields the same texture (it is deterministic).
     */
    public function reconstructAt(int $tick): World
    {
        $checkpoint = $this->nearestAtOrBefore($tick);
        $world = $checkpoint->resume();
        if ($tick > $world->tick) {
            $world->advance($tick - $world->tick);
        }

        return $world;
    }

    /** The latest-anchored checkpoint standing at or before $tick — the cheapest point to replay from. */
    private function nearestAtOrBefore(int $tick): Checkpoint
    {
        $nearest = null;
        foreach ($this->checkpoints as $anchorTick => $checkpoint) {
            if ($anchorTick <= $tick && ($nearest === null || $anchorTick > $nearest->tick)) {
                $nearest = $checkpoint;
            }
        }
        if ($nearest === null) {
            throw new RuntimeException("no checkpoint anchored at or before tick {$tick}");
        }

        return $nearest;
    }
}
