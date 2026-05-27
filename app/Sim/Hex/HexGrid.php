<?php

namespace App\Sim\Hex;

/**
 * The play grid: a rectangular block of {@see Hex}es projected from a world's continuous fields at a
 * chosen resolution (design doc 16; TWT-275). Indexed by {@see HexCoord}; adjacency is the clean axial
 * six. A deterministic projection (texture) — the same world + resolution yields the same grid; the macro
 * sim core stays continuous and agent-based.
 */
final readonly class HexGrid
{
    /** @var array<string,Hex> by coordinate key */
    private array $byCoord;

    /** @param list<Hex> $hexes */
    public function __construct(
        public int $cols,
        public int $rows,
        public array $hexes,
    ) {
        $byCoord = [];
        foreach ($hexes as $hex) {
            $byCoord[$hex->coord->key()] = $hex;
        }
        $this->byCoord = $byCoord;
    }

    public function at(HexCoord $coord): ?Hex
    {
        return $this->byCoord[$coord->key()] ?? null;
    }

    /** @return list<Hex> the adjacent hexes that exist in the grid (fewer than six at an edge) */
    public function neighbours(HexCoord $coord): array
    {
        $out = [];
        foreach ($coord->neighbours() as $neighbour) {
            $hex = $this->at($neighbour);
            if ($hex !== null) {
                $out[] = $hex;
            }
        }

        return $out;
    }
}
