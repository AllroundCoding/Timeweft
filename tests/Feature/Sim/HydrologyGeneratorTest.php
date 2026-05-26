<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\CirculationGenerator;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\HydrologyGenerator;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use PHPUnit\Framework\TestCase;

/**
 * TWT-131/81 — rainfall routed over the terrain into rivers and lakes. Deterministic per seed; rivers
 * should form, concentrate flow downstream, and sit on land; lakes pool in basins on land.
 */
class HydrologyGeneratorTest extends TestCase
{
    public function test_drainage_is_a_pure_function_of_terrain_and_rainfall(): void
    {
        [$substrate, $climate] = $this->world();
        $a = HydrologyGenerator::generate($substrate, $climate);
        $b = HydrologyGenerator::generate($substrate, $climate);

        $this->assertSame($a->flow, $b->flow, 'same inputs → the same flow');
        $this->assertSame($a->river, $b->river, 'same inputs → the same rivers');
        $this->assertSame($a->lake, $b->lake, 'same inputs → the same lakes');
    }

    public function test_rivers_form_and_run_on_land(): void
    {
        [$substrate, $climate] = $this->world();
        $hydrology = HydrologyGenerator::generate($substrate, $climate);

        $rivers = 0;
        for ($y = 0; $y < $substrate->height; $y++) {
            for ($x = 0; $x < $substrate->width; $x++) {
                if ($hydrology->isRiver($x, $y)) {
                    $rivers++;
                    $this->assertTrue($substrate->isLand($x, $y), 'a river runs over land, not open sea');
                }
            }
        }

        $this->assertGreaterThan(0, $rivers, 'a rainy, mountainous world grows rivers');
    }

    public function test_lakes_sit_on_land(): void
    {
        [$substrate, $climate] = $this->world();
        $hydrology = HydrologyGenerator::generate($substrate, $climate);

        for ($y = 0; $y < $substrate->height; $y++) {
            for ($x = 0; $x < $substrate->width; $x++) {
                if ($hydrology->isLake($x, $y)) {
                    $this->assertTrue($substrate->isLand($x, $y), 'a lake pools in a basin on land');
                }
            }
        }
    }

    public function test_flow_concentrates_into_channels(): void
    {
        [$substrate, $climate] = $this->world();
        $hydrology = HydrologyGenerator::generate($substrate, $climate);

        $max = 0.0;
        $min = INF;
        $sum = 0.0;
        $count = 0;
        for ($y = 0; $y < $substrate->height; $y++) {
            for ($x = 0; $x < $substrate->width; $x++) {
                $flow = $hydrology->flowAt($x, $y);
                $max = max($max, $flow);
                $min = min($min, $flow);
                $sum += $flow;
                $count++;
            }
        }

        $mean = $sum / max(1, $count);
        $this->assertGreaterThanOrEqual(0.0, $min, 'no cell carries negative water');
        $this->assertGreaterThan($mean * 10.0, $max, 'water gathers into channels far above the average cell');
    }

    /** @return array{0: Substrate, 1: Climate} */
    private function world(string $seed = 'vaeris', int $width = 160, int $height = 100, int $plates = 12): array
    {
        $rng = new Rng($seed);
        $substrate = SubstrateGenerator::generate($rng, $width, $height, $plates);
        $circulation = CirculationGenerator::generate($rng, $substrate);

        return [$substrate, ClimateGenerator::generate($rng, $substrate, $circulation)];
    }
}
