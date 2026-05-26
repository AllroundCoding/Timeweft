<?php

namespace App\Sim\Worldgen;

final class Circulation
{
    /**
     * @param  list<list<float>>  $windU  horizontal wind per cell (-1 west … 1 east)
     * @param  list<list<float>>  $windV  vertical wind per cell (-1 north … 1 south)
     * @param  list<list<float>>  $currentU  horizontal ocean current per cell
     * @param  list<list<float>>  $currentV  vertical ocean current per cell
     * @param  list<list<float>>  $currentTemp  current warmth per cell (-1 cold … 1 warm)
     */
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly array $windU,       // Horizontal wind (-1.0 West, 1.0 East)
        public readonly array $windV,       // Vertical wind (-1.0 North, 1.0 South)
        public readonly array $currentU,    // Horizontal current
        public readonly array $currentV,    // Vertical current
        public readonly array $currentTemp, // -1.0 (Cold) to 1.0 (Warm)
    ) {}

    /**
     * The normalized wind vector for a cell.
     *
     * @return array{0: float, 1: float}
     */
    public function windAt(int $x, int $y): array
    {
        return [$this->windU[$y][$x], $this->windV[$y][$x]];
    }
}
