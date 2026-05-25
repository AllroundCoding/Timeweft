<?php

namespace App\Sim\Worldgen;

/**
 * Water routed over the terrain (design doc 13; TWT-131): rainfall ({@see Climate}) flows downhill across
 * the frozen {@see Substrate}, accumulating into rivers and pooling in sinks as lakes. A first pass — the
 * drainage network and standing water; floodplains, aquifers/springs (oases), and named watersheds are
 * deferred.
 *
 * Immutable and grid-indexed [y][x]; a pure function of substrate + climate, so the same seed yields the
 * same rivers.
 */
readonly class Hydrology
{
    /**
     * @param  list<list<float>>  $flow  accumulated upstream water passing through each cell
     * @param  list<list<bool>>  $river  cells carrying a river (flow past the channel threshold, on land)
     * @param  list<list<bool>>  $lake  cells holding standing inland water (a sink that gathers real drainage)
     * @param  list<list<bool>>  $delta  cells where a major river meets the sea (river mouths)
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $flow,
        public array $river,
        public array $lake,
        public array $delta,
    ) {}

    public function flowAt(int $x, int $y): float
    {
        return $this->flow[$y][$x];
    }

    public function isRiver(int $x, int $y): bool
    {
        return $this->river[$y][$x];
    }

    public function isLake(int $x, int $y): bool
    {
        return $this->lake[$y][$x];
    }

    public function isDelta(int $x, int $y): bool
    {
        return $this->delta[$y][$x];
    }
}
