<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\Biome;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use PHPUnit\Framework\TestCase;

/**
 * TWT-132 — the climate surface derived from the substrate. A first pass: temperature, precipitation,
 * fertility, and a coarse biome map. Deterministic per seed; the physics should read true — a warm
 * equator and cold poles, colder peaks, barren seas, and a world of varied biomes.
 */
class ClimateGeneratorTest extends TestCase
{
    public function test_climate_is_a_pure_function_of_the_substrate(): void
    {
        $a = ClimateGenerator::generate($this->substrate());
        $b = ClimateGenerator::generate($this->substrate());

        $this->assertSame($a->temperature, $b->temperature, 'same seed → the same temperatures');
        $this->assertSame($a->precipitation, $b->precipitation, 'same seed → the same rainfall');
        $this->assertSame($a->fertility, $b->fertility, 'same seed → the same fertility');
        $this->assertSame($a->biome, $b->biome, 'same seed → the same biomes');
    }

    public function test_the_equator_runs_warmer_than_the_poles(): void
    {
        $climate = ClimateGenerator::generate($this->substrate());

        $equator = $this->rowMeanTemperature($climate, intdiv($climate->height, 2));
        $poles = ($this->rowMeanTemperature($climate, 0) + $this->rowMeanTemperature($climate, $climate->height - 1)) / 2.0;

        $this->assertGreaterThan($poles + 10.0, $equator, 'the equator is much warmer than the poles');
    }

    public function test_higher_ground_is_colder_at_the_same_latitude(): void
    {
        $substrate = $this->substrate();
        $climate = ClimateGenerator::generate($substrate);
        $y = intdiv($climate->height, 2);

        $highest = null;
        $lowest = null;
        $highestElevation = -INF;
        $lowestElevation = INF;
        for ($x = 0; $x < $climate->width; $x++) {
            if (! $substrate->isLand($x, $y)) {
                continue;
            }
            $elevation = $substrate->elevationAt($x, $y);
            if ($elevation > $highestElevation) {
                $highestElevation = $elevation;
                $highest = $x;
            }
            if ($elevation < $lowestElevation) {
                $lowestElevation = $elevation;
                $lowest = $x;
            }
        }

        $this->assertNotNull($highest);
        $this->assertNotNull($lowest);
        $this->assertNotSame($highest, $lowest, 'the equator row holds varied terrain');
        $this->assertLessThan(
            $climate->temperatureAt($lowest, $y),
            $climate->temperatureAt($highest, $y),
            'the mountain cell is colder than the lowland in the same row',
        );
    }

    public function test_seas_are_barren_and_some_land_is_fertile(): void
    {
        $substrate = $this->substrate();
        $climate = ClimateGenerator::generate($substrate);

        $seasBarren = true;
        $fertileLand = 0;
        for ($y = 0; $y < $climate->height; $y++) {
            for ($x = 0; $x < $climate->width; $x++) {
                if ($substrate->isLand($x, $y)) {
                    if ($climate->fertilityAt($x, $y) > 0.25) {
                        $fertileLand++;
                    }
                } elseif ($climate->fertilityAt($x, $y) !== 0.0) {
                    $seasBarren = false;
                }
            }
        }

        $this->assertTrue($seasBarren, 'open water grows no crops');
        $this->assertGreaterThan(0, $fertileLand, 'somewhere on the map is good farmland');
    }

    public function test_a_whole_world_grows_several_biomes(): void
    {
        $climate = ClimateGenerator::generate($this->substrate());

        $kinds = [];
        foreach ($climate->biome as $row) {
            foreach ($row as $biome) {
                $kinds[$biome->value] = true;
            }
        }

        $this->assertGreaterThanOrEqual(4, count($kinds), 'a whole world wears varied biomes');
        $this->assertArrayHasKey(Biome::Ocean->value, $kinds, 'and it has seas');
    }

    private function substrate(string $seed = 'vaeris', int $width = 80, int $height = 50, int $plates = 10): Substrate
    {
        return SubstrateGenerator::generate(new Rng($seed), $width, $height, $plates);
    }

    private function rowMeanTemperature(Climate $climate, int $y): float
    {
        $sum = 0.0;
        for ($x = 0; $x < $climate->width; $x++) {
            $sum += $climate->temperatureAt($x, $y);
        }

        return $sum / $climate->width;
    }
}
