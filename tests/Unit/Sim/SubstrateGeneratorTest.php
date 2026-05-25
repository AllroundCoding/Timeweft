<?php

namespace Tests\Unit\Sim;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use PHPUnit\Framework\TestCase;

/**
 * TWT-130: the solid-earth substrate derives a believable world from a few plate seeds —
 * deterministically (same seed → same world), with both land and sea, mountains thrown up where
 * plates converge, and ore pooled in the worked crust.
 */
class SubstrateGeneratorTest extends TestCase
{
    public function test_the_same_seed_reproduces_the_same_world(): void
    {
        $first = SubstrateGenerator::generate(new Rng('vaeris'), 48, 32, 7);
        $second = SubstrateGenerator::generate(new Rng('vaeris'), 48, 32, 7);

        $this->assertSame($first->elevation, $second->elevation, 'same seed → identical relief');
        $this->assertSame($first->minerals, $second->minerals);
    }

    public function test_different_seeds_make_different_worlds(): void
    {
        $a = SubstrateGenerator::generate(new Rng('vaeris'), 48, 32, 7);
        $b = SubstrateGenerator::generate(new Rng('khoradun'), 48, 32, 7);

        $this->assertNotSame($a->elevation, $b->elevation);
    }

    public function test_a_world_has_both_land_and_sea(): void
    {
        $substrate = SubstrateGenerator::generate(new Rng('vaeris'), 64, 48, 8);

        $this->assertGreaterThan(0.0, $substrate->landFraction(), 'there is dry land');
        $this->assertLessThan(1.0, $substrate->landFraction(), 'and there is sea');
    }

    public function test_convergence_throws_up_mountains_above_the_flat_crust(): void
    {
        $substrate = SubstrateGenerator::generate(new Rng('vaeris'), 64, 48, 8);

        // Uplift at converging boundaries lifts peaks well above resting continental crust (0.25).
        $this->assertGreaterThan(0.6, $substrate->highestElevation(), 'plate convergence builds mountains');
    }

    public function test_minerals_pool_in_the_worked_crust_not_everywhere(): void
    {
        $substrate = SubstrateGenerator::generate(new Rng('vaeris'), 64, 48, 8);

        [$max, $mean] = $this->mineralStats($substrate);
        $this->assertGreaterThan(0.2, $max, 'tectonic zones are mineral-rich');
        $this->assertLessThan($max * 0.5, $mean, 'but ore is concentrated, not spread evenly');
    }

    public function test_fractal_relief_breaks_up_the_flat_crust(): void
    {
        $substrate = SubstrateGenerator::generate(new Rng('vaeris'), 64, 48, 8);

        $distinct = [];
        for ($y = 0; $y < $substrate->height; $y++) {
            for ($x = 0; $x < $substrate->width; $x++) {
                $distinct[(string) $substrate->elevationAt($x, $y)] = true;
            }
        }

        $cells = $substrate->width * $substrate->height;
        $this->assertGreaterThan($cells * 0.8, count($distinct), 'relief gives nearly every cell its own height — no dead-flat plateaus to sheet-drain');
    }

    /** @return array{0: float, 1: float} the highest and mean mineral concentration */
    private function mineralStats(Substrate $substrate): array
    {
        $max = 0.0;
        $sum = 0.0;
        $count = 0;
        for ($y = 0; $y < $substrate->height; $y++) {
            for ($x = 0; $x < $substrate->width; $x++) {
                $value = $substrate->mineralAt($x, $y);
                $max = max($max, $value);
                $sum += $value;
                $count++;
            }
        }

        return [$max, $sum / max(1, $count)];
    }
}
