<?php

namespace App\Sim\Worldgen;

/**
 * A tectonic plate seed (design doc 13; TWT-130) — the few authored inputs from which the solid-earth
 * substrate is derived. A plate has a seed position, a kind (continental floats high, oceanic sits
 * low), and a drift vector; where two plates meet, the relative drift decides whether the crust is
 * pushed up into mountains or pulled apart into a rift.
 */
readonly class Plate
{
    public function __construct(
        public int $id,
        public float $x,
        public float $y,
        public bool $continental,
        public float $driftX,
        public float $driftY,
    ) {}
}
