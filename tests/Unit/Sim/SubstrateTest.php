<?php

namespace Tests\Unit\Sim;

use App\Sim\Worldgen\Substrate;
use PHPUnit\Framework\TestCase;

/**
 * The substrate is an orb projected onto a flat map: longitude (X) wraps seam-free around the globe,
 * latitude (Y) is capped at the poles. {@see Substrate::slopeAt()} must honour both.
 */
class SubstrateTest extends TestCase
{
    public function test_slope_wraps_around_the_globe_longitudinally(): void
    {
        // A cliff between the last column and the first: on a globe they're neighbours, so x=0 must see
        // the full drop to x=2 (its wrapped west neighbour), not be clipped at the map edge.
        $elevation = [
            [5.0, 1.0, 0.0],
            [5.0, 1.0, 0.0],
            [5.0, 1.0, 0.0],
        ];
        $substrate = new Substrate(3, 3, $elevation, $this->ints(3, 3), $this->floats(3, 3), []);

        $this->assertSame(5.0, $substrate->slopeAt(0, 1), 'the first column sees the last as its west neighbour');
    }

    public function test_latitude_is_capped_at_the_poles(): void
    {
        // Stepping "north" off the pole row finds nothing (latitude does not wrap), so only the in-bounds
        // neighbours count — and no out-of-bounds read happens.
        $elevation = [
            [9.0, 9.0, 9.0], // y=0, the pole row
            [1.0, 1.0, 1.0],
        ];
        $substrate = new Substrate(3, 2, $elevation, $this->ints(3, 2), $this->floats(3, 2), []);

        $this->assertSame(8.0, $substrate->slopeAt(1, 0), 'the pole row drops only to the south, never off the top');
    }

    /** @return list<list<int>> */
    private function ints(int $width, int $height): array
    {
        return array_fill(0, $height, array_fill(0, $width, 0));
    }

    /** @return list<list<float>> */
    private function floats(int $width, int $height): array
    {
        return array_fill(0, $height, array_fill(0, $width, 0.0));
    }
}
