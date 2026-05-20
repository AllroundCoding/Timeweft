<?php

namespace App\Sim\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Deterministic, seedable RNG. Every run with the same seed reproduces the same
 * world — the property that later makes derive-on-demand and retroactive ripple
 * legible rather than chaotic.
 */
final class Rng
{
    private Randomizer $r;

    public function __construct(int|string $seed)
    {
        $intSeed = is_int($seed) ? $seed : (int) crc32($seed);
        $this->r = new Randomizer(new Mt19937($intSeed));
    }

    public function int(int $min, int $max): int
    {
        return $this->r->getInt($min, $max);
    }

    public function float(float $min = 0.0, float $max = 1.0): float
    {
        return $this->r->getFloat($min, $max);
    }

    public function chance(float $probability): bool
    {
        return $this->r->nextFloat() < $probability;
    }

    /**
     * @template T
     * @param array<int,T> $items
     * @return T
     */
    public function pick(array $items)
    {
        return $items[$this->r->getInt(0, count($items) - 1)];
    }
}
