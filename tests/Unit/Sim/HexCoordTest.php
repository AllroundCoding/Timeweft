<?php

namespace Tests\Unit\Sim;

use App\Sim\Hex\HexCoord;
use PHPUnit\Framework\TestCase;

/**
 * TWT-275 — axial hex coordinates: clean six-neighbour adjacency and cube distance, the spatial unit the
 * play grid is addressed by.
 */
class HexCoordTest extends TestCase
{
    public function test_a_hex_has_six_neighbours_each_one_step_away(): void
    {
        $centre = new HexCoord(0, 0);
        $neighbours = $centre->neighbours();

        $this->assertCount(6, $neighbours);
        foreach ($neighbours as $neighbour) {
            $this->assertSame(1, $centre->distanceTo($neighbour), 'every neighbour is exactly one hex away');
        }

        // The six are distinct.
        $keys = array_map(static fn (HexCoord $c): string => $c->key(), $neighbours);
        $this->assertCount(6, array_unique($keys));
    }

    public function test_distance_is_the_cube_hex_distance(): void
    {
        $origin = new HexCoord(0, 0);

        $this->assertSame(0, $origin->distanceTo($origin));
        $this->assertSame(3, $origin->distanceTo(new HexCoord(3, 0)));
        $this->assertSame(3, $origin->distanceTo(new HexCoord(0, 3)));
        $this->assertSame(3, $origin->distanceTo(new HexCoord(-3, 3)), 'along a single axis');
        $this->assertSame(5, $origin->distanceTo(new HexCoord(3, 2)), 'across two axes');
        // Distance is symmetric.
        $this->assertSame(
            $origin->distanceTo(new HexCoord(3, 2)),
            (new HexCoord(3, 2))->distanceTo($origin),
        );
    }

    public function test_the_key_is_stable(): void
    {
        $this->assertSame('3,-2', (new HexCoord(3, -2))->key());
    }
}
