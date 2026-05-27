<?php

namespace App\Sim\Hex;

/**
 * An axial hex coordinate (q, r) — the play grid's spatial unit (design doc 16; TWT-275). Axial gives
 * clean, ambiguity-free adjacency: six neighbours at even distance, no diagonals. The third cube axis is
 * implicit (s = -q - r), used only for distance.
 *
 * A pure value object — no world state, no RNG; the hex *grid* is a deterministic projection of the
 * continuous worldgen ({@see HexMapProjector}), and this is its address.
 */
final readonly class HexCoord
{
    /** The six axial neighbour directions, clockwise from east (pointy-top). */
    private const DIRECTIONS = [[1, 0], [1, -1], [0, -1], [-1, 0], [-1, 1], [0, 1]];

    public function __construct(
        public int $q,
        public int $r,
    ) {}

    /** @return list<HexCoord> the six adjacent coordinates, clockwise */
    public function neighbours(): array
    {
        $out = [];
        foreach (self::DIRECTIONS as [$dq, $dr]) {
            $out[] = new self($this->q + $dq, $this->r + $dr);
        }

        return $out;
    }

    /** Hex distance — the number of steps between two hexes, via cube coordinates. */
    public function distanceTo(self $other): int
    {
        return intdiv(
            abs($this->q - $other->q)
            + abs($this->q + $this->r - $other->q - $other->r)
            + abs($this->r - $other->r),
            2,
        );
    }

    /** A stable string key for indexing (e.g. "3,-2"). */
    public function key(): string
    {
        return $this->q.','.$this->r;
    }
}
