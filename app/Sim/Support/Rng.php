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

    private readonly int $seed;

    public function __construct(int|string $seed)
    {
        $this->seed = is_int($seed) ? $seed : (int) crc32($seed);
        $this->r = new Randomizer(new Mt19937($this->seed));
    }

    /**
     * An independent deterministic sub-stream, salted off this one's seed. Sampling the fork never
     * advances this generator, so a new RNG consumer can be added without perturbing existing streams
     * (e.g. yearly harvest variance must not shift the seeded births and deaths).
     */
    public function fork(int|string $salt): self
    {
        return new self((int) crc32($this->seed.'/'.$salt));
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
     *
     * @param  array<int,T>  $items
     * @return T
     */
    public function pick(array $items)
    {
        return $items[$this->r->getInt(0, count($items) - 1)];
    }
}
