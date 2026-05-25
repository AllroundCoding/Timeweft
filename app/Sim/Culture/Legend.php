<?php

namespace App\Sim\Culture;

use App\Sim\Persistence\Skeleton;

/**
 * An in-world legend (TWT-143): a real chronicle event that a people remembers and embellishes over
 * generations into myth — the mythologised layer atop the factual chronicle. Durable canon (skeleton),
 * always traceable back to the {@see $sourceEventId} that seeded it; its {@see $telling} grows more
 * mythic as {@see $embellishment} climbs with age (the mundane turns miraculous, the cause becomes fate).
 *
 * One legend per source event in this first cut, so the source event's id is the legend's identity. The
 * source facts are immutable; only the embellishment and its retelling drift.
 */
final class Legend implements Skeleton
{
    public function __construct(
        public readonly int $sourceEventId,
        public readonly string $motif,        // the source event's type — the specific shape (plague, war, founding…)
        public readonly LegendKind $kind,
        public readonly string $rememberedBy, // the settlement whose people keep the tale
        public readonly ?int $heroId,         // the named soul at its heart, if any
        public readonly ?string $heroName,
        public readonly int $eventYear,       // the in-world year the real event happened
        public readonly int $bornTick,        // the tick it first crystallised into legend
        public float $embellishment,          // 0..1 — how mythologised it has become; grows with age
        public string $title,
        public string $telling,
    ) {}
}
