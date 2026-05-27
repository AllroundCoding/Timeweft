<?php

namespace Tests\Feature\Sim;

use App\Sim\Worldgen\AccessibilityGenerator;
use App\Sim\Worldgen\Hydrology;
use App\Sim\Worldgen\Substrate;
use PHPUnit\Framework\TestCase;

/**
 * TWT-136 — the travel-cost / accessibility surface. Friction ranks terrain by how it slows movement, and
 * least-cost travel time falls out of it (Dijkstra over the globe-topology grid). A read-only derived
 * utility, not wired into migration/trade, so the canonical run is untouched.
 */
class AccessibilityFieldTest extends TestCase
{
    /**
     * Assemble a substrate + hydrology from a plain elevation grid plus river/lake markers — enough to
     * exercise the friction model and the cost surface.
     *
     * @param  list<list<float>>  $elevation  [y][x]
     * @param  list<array{int,int}>  $rivers  [x,y] cells carrying a river
     * @param  list<array{int,int}>  $lakes  [x,y] cells holding a lake
     * @return array{Substrate, Hydrology}
     */
    private function world(array $elevation, array $rivers = [], array $lakes = []): array
    {
        $height = count($elevation);
        $width = count($elevation[0]);

        $ints = array_fill(0, $height, array_fill(0, $width, 0));
        $floats = array_fill(0, $height, array_fill(0, $width, 0.0));
        $bools = array_fill(0, $height, array_fill(0, $width, false));

        $substrate = new Substrate($width, $height, $elevation, $ints, $floats, []);

        $river = $bools;
        $lake = $bools;
        foreach ($rivers as [$x, $y]) {
            $river[$y][$x] = true;
        }
        foreach ($lakes as [$x, $y]) {
            $lake[$y][$x] = true;
        }
        $hydrology = new Hydrology($width, $height, $floats, $river, $lake, $bools);

        return [$substrate, $hydrology];
    }

    public function test_friction_ranks_terrain_from_river_to_open_sea(): void
    {
        // A flat plateau of 5s with a steep peak at (1,1) and a patch of sea at (3,1).
        [$substrate, $hydrology] = $this->world(
            elevation: [
                [5.0, 5.0, 5.0, 5.0],
                [5.0, 20.0, 5.0, -1.0],
                [5.0, 5.0, 5.0, 5.0],
            ],
            rivers: [[2, 2]],
            lakes: [[0, 2]],
        );
        $field = AccessibilityGenerator::generate($substrate, $hydrology);

        $river = $field->frictionAt(2, 2);
        $plains = $field->frictionAt(0, 0);
        $steep = $field->frictionAt(1, 1);
        $lake = $field->frictionAt(0, 2);
        $sea = $field->frictionAt(3, 1);

        $this->assertLessThan($plains, $river, 'a river is the easiest going');
        $this->assertLessThan($steep, $plains, 'flat plains beat a steep climb');
        $this->assertLessThan($lake, $steep, 'a lake is harder than any land');
        $this->assertLessThanOrEqual($sea, $lake, 'open sea is the worst');
        $this->assertEqualsWithDelta(AccessibilityGenerator::SEA_FRICTION, $sea, 1e-9);
    }

    public function test_a_river_is_a_highway(): void
    {
        // A flat six-cell ring; only the forward arc 1→2 carries a river.
        $flat = [[1.0, 1.0, 1.0, 1.0, 1.0, 1.0]];

        [$plainSub, $plainHydro] = $this->world($flat);
        [$riverSub, $riverHydro] = $this->world($flat, rivers: [[1, 0], [2, 0]]);

        $plainTime = AccessibilityGenerator::generate($plainSub, $plainHydro)->travelTime(0, 0, 3, 0);
        $riverTime = AccessibilityGenerator::generate($riverSub, $riverHydro)->travelTime(0, 0, 3, 0);

        $this->assertLessThan($plainTime, $riverTime, 'the river corridor is the cheaper road');
    }

    public function test_routes_are_symmetric_and_wrap_the_globe(): void
    {
        $flat = [[1.0, 1.0, 1.0, 1.0, 1.0, 1.0]];
        [$substrate, $hydrology] = $this->world($flat);
        $field = AccessibilityGenerator::generate($substrate, $hydrology);

        // Symmetric: the cost there equals the cost back.
        $this->assertEqualsWithDelta($field->travelTime(0, 0, 4, 0), $field->travelTime(4, 0, 0, 0), 1e-9);

        // Longitude wraps: cells 0 and 5 are neighbours, so the trip is one cheap step, not five.
        $this->assertEqualsWithDelta(1.0, $field->travelTime(0, 0, 5, 0), 1e-9, 'the short way is around the back of the globe');
        $this->assertEqualsWithDelta(0.0, $field->travelTime(2, 0, 2, 0), 1e-9, 'no distance to oneself');
    }

    public function test_the_surface_is_deterministic(): void
    {
        [$substrate, $hydrology] = $this->world(
            elevation: [[3.0, 8.0, 2.0], [1.0, 5.0, 4.0]],
            rivers: [[0, 1]],
        );

        $first = AccessibilityGenerator::generate($substrate, $hydrology);
        $second = AccessibilityGenerator::generate($substrate, $hydrology);

        $this->assertSame($first->friction, $second->friction);
        $this->assertEqualsWithDelta(
            $first->travelTime(0, 0, 2, 1),
            $second->travelTime(0, 0, 2, 1),
            1e-12,
        );
    }
}
