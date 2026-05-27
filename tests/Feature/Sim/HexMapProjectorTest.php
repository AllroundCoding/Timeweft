<?php

namespace Tests\Feature\Sim;

use App\Sim\Hex\Hex;
use App\Sim\Hex\HexCoord;
use App\Sim\Hex\HexGrid;
use App\Sim\Hex\HexMapProjector;
use App\Sim\Worldgen\Biome;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\Hydrology;
use App\Sim\Worldgen\Substrate;
use PHPUnit\Framework\TestCase;

/**
 * TWT-275 — the hex map is a deterministic projection of the continuous worldgen: each hex samples the
 * terrain/biome/water/fertility beneath it and derives a movement cost. Same world + resolution → same
 * grid. Pure texture, not wired into the sim, so the canonical run is untouched.
 */
class HexMapProjectorTest extends TestCase
{
    private Substrate $substrate;

    private Climate $climate;

    private Hydrology $hydrology;

    protected function setUp(): void
    {
        // A 4x3 raster: sea + a steep peak on the top row, a river at (2,2), a lake at (0,2).
        $elevation = [
            [-1.0, 5.0, 5.0, 20.0],
            [5.0, 5.0, 5.0, 5.0],
            [5.0, 5.0, 5.0, 5.0],
        ];
        $biome = [
            [Biome::Ocean, Biome::Grassland, Biome::Desert, Biome::Tundra],
            [Biome::Grassland, Biome::Forest, Biome::Grassland, Biome::Grassland],
            [Biome::Grassland, Biome::Grassland, Biome::Grassland, Biome::Grassland],
        ];
        $fertility = [
            [0.0, 0.5, 0.1, 0.0],
            [0.5, 0.7, 0.5, 0.5],
            [0.5, 0.5, 0.5, 0.5],
        ];

        $w = 4;
        $h = 3;
        $ints = array_fill(0, $h, array_fill(0, $w, 0));
        $floats = array_fill(0, $h, array_fill(0, $w, 0.0));
        $bools = array_fill(0, $h, array_fill(0, $w, false));

        $this->substrate = new Substrate($w, $h, $elevation, $ints, $floats, []);
        $this->climate = new Climate($w, $h, $floats, $floats, $fertility, $biome);

        $river = $bools;
        $river[2][2] = true;
        $lake = $bools;
        $lake[2][0] = true;
        $this->hydrology = new Hydrology($w, $h, $floats, $river, $lake, $bools);
    }

    /** At a 4x3 hex resolution each hex maps 1:1 onto a raster cell — hex (q,r) samples cell (q,r). */
    private function grid(): HexGrid
    {
        return HexMapProjector::project($this->substrate, $this->climate, $this->hydrology, 4, 3);
    }

    public function test_the_grid_has_one_hex_per_resolution_cell(): void
    {
        $grid = $this->grid();

        $this->assertSame(4, $grid->cols);
        $this->assertSame(3, $grid->rows);
        $this->assertCount(12, $grid->hexes);
    }

    public function test_each_hex_samples_the_world_beneath_it(): void
    {
        $grid = $this->grid();

        $sea = $grid->at(new HexCoord(0, 0));
        $this->assertNotNull($sea);
        $this->assertSame(Biome::Ocean, $sea->biome);
        $this->assertFalse($sea->isLand);
        $this->assertTrue($sea->isWater());
        $this->assertEqualsWithDelta(10.0, $sea->movementCost, 1e-9, 'open water is costly');

        $land = $grid->at(new HexCoord(1, 1));
        $this->assertNotNull($land);
        $this->assertSame(Biome::Forest, $land->biome);
        $this->assertTrue($land->isLand);
        $this->assertEqualsWithDelta(0.7, $land->fertility, 1e-9);
        $this->assertEqualsWithDelta(1.0, $land->movementCost, 1e-9, 'flat land is the baseline');

        $peak = $grid->at(new HexCoord(3, 0));
        $this->assertNotNull($peak);
        $this->assertGreaterThan($land->movementCost, $peak->movementCost, 'a steep slope costs more');
    }

    public function test_rivers_are_highways_and_lakes_are_water(): void
    {
        $grid = $this->grid();

        $river = $grid->at(new HexCoord(2, 2));
        $this->assertNotNull($river);
        $this->assertTrue($river->isRiver);
        $this->assertEqualsWithDelta(0.5, $river->movementCost, 1e-9, 'a river is the cheapest going');

        $lake = $grid->at(new HexCoord(0, 2));
        $this->assertNotNull($lake);
        $this->assertTrue($lake->isWater());
        $this->assertEqualsWithDelta(10.0, $lake->movementCost, 1e-9);
    }

    public function test_adjacency_follows_the_axial_six(): void
    {
        $grid = $this->grid();

        $this->assertCount(6, $grid->neighbours(new HexCoord(1, 1)), 'an interior hex has all six neighbours');
        $this->assertCount(2, $grid->neighbours(new HexCoord(0, 0)), 'a corner has fewer');
    }

    public function test_the_projection_is_deterministic(): void
    {
        $this->assertSame($this->fingerprint($this->grid()), $this->fingerprint($this->grid()));
    }

    /** @return list<string> */
    private function fingerprint(HexGrid $grid): array
    {
        $rows = array_map(static fn (Hex $h): string => sprintf(
            '%s|%s|%.3f|%d%d%d|%.3f|%.3f',
            $h->coord->key(), $h->biome->value, $h->elevation,
            (int) $h->isLand, (int) $h->isRiver, (int) $h->isLake, $h->fertility, $h->movementCost,
        ), $grid->hexes);
        sort($rows);

        return $rows;
    }
}
