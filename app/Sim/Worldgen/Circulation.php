<?php

namespace App\Sim\Worldgen;

final class Circulation
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $windU,       // Horizontal wind (-1.0 West, 1.0 East)
        public readonly array $windV,       // Vertical wind (-1.0 North, 1.0 South)
        public readonly array $currentU,    // Horizontal current
        public readonly array $currentV,    // Vertical current
        public readonly array $currentTemp, // -1.0 (Cold) to 1.0 (Warm)
    ) {}

    /** Get the normalized wind vector for a cell. */
    public function windAt(int $x, int $y): array
    {
        return [$this->windU[$y][$x], $this->windV[$y][$x]];
    }
}
