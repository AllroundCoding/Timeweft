<?php

namespace App\Sim\Persistence;

use App\Sim\World\World;

/**
 * A checkpoint (design doc 01; TWT-32) — a snapshot of a world's boundary state at a tick, plus the seed,
 * from which a stretch of history replays deterministically. It is what makes derive-on-demand cheap
 * (TWT-38): rebuild the nearest checkpoint and replay forward rather than re-running from t=0, and what
 * makes editing tractable — replay a counterfactual from just before the edit.
 *
 * Replay is exact because the engine's randomness is forked sub-streams keyed by (concern, entity, epoch)
 * off the immutable seed (TWT-107) — the main generator accumulates no draw-state — so the seed plus the
 * boundary state is all that is needed: a world resumed from a checkpoint and advanced is byte-identical
 * to one that never stopped.
 *
 * The snapshot is taken once at capture and is immutable thereafter, so the live world may keep advancing
 * without disturbing it, and {@see resume} hands back a fresh, independent copy each time.
 */
final class Checkpoint implements Skeleton
{
    private function __construct(
        public readonly int $tick,
        public readonly int $seed,
        private readonly string $boundaryState,
    ) {}

    public static function of(World $world): self
    {
        return new self($world->tick, $world->rng->seed(), serialize($world));
    }

    /** Reconstruct the world as it stood at the checkpoint — a fresh deep copy, resumable any number of times. */
    public function resume(): World
    {
        $world = unserialize($this->boundaryState);
        assert($world instanceof World);

        return $world;
    }
}
