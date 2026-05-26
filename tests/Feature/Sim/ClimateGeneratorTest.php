<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\CirculationGenerator;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use PHPUnit\Framework\TestCase;

/**
 * TWT-132/77 — the climate surface derived from the substrate + circulation. Deterministic per seed; the
 * physics should read true — a warm equator and cold poles, colder mountains, barren seas, varied biomes.
 */
class ClimateGeneratorTest extends TestCase
{
    public function test_climate_is_a_pure_function_of_the_world(): void
    {
        [, $a] = $this->world();
        [, $b] = $this->world();

        $this->assertSame($a->temperature, $b->temperature, 'same seed → the same temperatures');
        $this->assertSame($a->precipitation, $b->precipitation, 'same seed → the same rainfall');
        $this->assertSame($a->fertility, $b->fertility, 'same seed → the same fertility');
        $this->assertSame($a->biome, $b->biome, 'same seed → the same biomes');
    }

    public function test_the_equator_runs_warmer_than_the_poles(): void
    {
        [, $climate] = $this->world();

        $equator = $this->rowMeanTemperature($climate, intdiv($climate->height, 2));
        $poles = ($this->rowMeanTemperature($climate, 0) + $this->rowMeanTemperature($climate, $climate->height - 1)) / 2.0;

        $this->assertGreaterThan($poles + 10.0, $equator, 'the equator is much warmer than the poles');
    }

    public function test_mountains_run_colder_than_lowlands(): void
    {
        [$substrate, $climate] = $this->world();

        // Gather land cells as [elevation, temperature], then compare the highest tenth against the lowest
        // tenth: the lapse rate should make high ground colder on average, latitude noise washing out.
        $cells = [];
        for ($y = 0; $y < $climate->height; $y++) {
            for ($x = 0; $x < $climate->width; $x++) {
                if ($substrate->isLand($x, $y)) {
                    $cells[] = [$substrate->elevationAt($x, $y), $climate->temperatureAt($x, $y)];
                }
            }
        }
        usort($cells, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $tenth = max(1, intdiv(count($cells), 10));
        $lowMean = $this->meanTemperature(array_slice($cells, 0, $tenth));
        $highMean = $this->meanTemperature(array_slice($cells, -$tenth));

        $this->assertLessThan($lowMean, $highMean, 'the highest ground is colder than the lowest');
    }

    public function test_seas_are_barren_and_some_land_is_fertile(): void
    {
        [$substrate, $climate] = $this->world();

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
        [, $climate] = $this->world();

        $kinds = [];
        foreach ($climate->biome as $row) {
            foreach ($row as $biome) {
                $kinds[$biome->value] = true;
            }
        }

        $this->assertGreaterThanOrEqual(4, count($kinds), 'a whole world wears varied biomes');
    }

    /** @return array{0: Substrate, 1: Climate} */
    private function world(string $seed = 'vaeris', int $width = 80, int $height = 50, int $plates = 10): array
    {
        $rng = new Rng($seed);
        $substrate = SubstrateGenerator::generate($rng, $width, $height, $plates);
        $circulation = CirculationGenerator::generate($rng, $substrate);

        return [$substrate, ClimateGenerator::generate($rng, $substrate, $circulation)];
    }

    private function rowMeanTemperature(Climate $climate, int $y): float
    {
        $sum = 0.0;
        for ($x = 0; $x < $climate->width; $x++) {
            $sum += $climate->temperatureAt($x, $y);
        }

        return $sum / $climate->width;
    }

    /** @param  list<array{0: float, 1: float}>  $cells */
    private function meanTemperature(array $cells): float
    {
        $sum = 0.0;
        foreach ($cells as [, $temperature]) {
            $sum += $temperature;
        }

        return $sum / max(1, count($cells));
    }
}
