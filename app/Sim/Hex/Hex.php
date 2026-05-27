<?php

namespace App\Sim\Hex;

use App\Sim\Worldgen\Biome;

/**
 * One hex of the play grid — what the continuous worldgen looks like, sampled at this hex's location
 * (design doc 16; TWT-275). The hex is a *projection* of the world beneath it (terrain, movement,
 * fertility, water), not a second source of truth: derived texture, recomputed from the fields, never
 * stored. Player-placed, path-dependent things on a hex (a built structure, claimed territory) are
 * skeleton and live elsewhere, routed through the canonical-event path.
 */
final readonly class Hex
{
    public function __construct(
        public HexCoord $coord,
        public Biome $biome,
        public float $elevation,
        public bool $isLand,
        public bool $isRiver,
        public bool $isLake,
        /** Agrarian potential 0..1 beneath the hex. */
        public float $fertility,
        /** Cost to move into this hex — low on rivers, high on open water, rising with slope. */
        public float $movementCost,
    ) {}

    public function isWater(): bool
    {
        return ! $this->isLand || $this->isLake;
    }
}
