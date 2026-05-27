<?php

namespace App\Sim\Play;

use App\Sim\Economy\JobRequest;

/**
 * A settlement's open job surfaced to the player as a quest (design doc 16; TWT-101). There is no
 * separate quest system — a quest *is* a {@see JobRequest} with a reward and the viewing
 * agent's fit attached. Derived texture from the labor market: the same world yields the same board, and
 * nothing here is canon until the agent actually does the work (which settles the need like any agent's).
 */
final readonly class Quest
{
    public function __construct(
        /** The kind of work — farming, water-bearing, building, tending. */
        public string $type,
        /** The job's scarcity-set money value — what fulfilling it is worth (Pricing, TWT-47). */
        public float $reward,
        /** The good the work supplies, if any (null for labour like building or tending). */
        public ?string $good,
        /** How short the settlement is of that good — how much fulfilling it would answer. */
        public float $shortfall,
        /** The viewing agent's natural fit for this work, 0..1 (its trait affinity). */
        public float $affinity,
        /** The wage's pull on labour, 0..1 — stronger when the need is acute. */
        public float $pull,
    ) {}
}
