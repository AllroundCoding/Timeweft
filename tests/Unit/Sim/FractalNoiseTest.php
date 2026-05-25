<?php

namespace Tests\Unit\Sim;

use App\Sim\Worldgen\FractalNoise;
use PHPUnit\Framework\TestCase;

/**
 * TWT-262 — deterministic fractal noise used to give the substrate organic relief. Same seed → same
 * value everywhere; different seeds diverge; output stays in [-1, 1].
 */
class FractalNoiseTest extends TestCase
{
    public function test_it_is_deterministic(): void
    {
        $a = new FractalNoise(12345);
        $b = new FractalNoise(12345);

        for ($i = 0; $i < 20; $i++) {
            $x = $i * 3.7;
            $y = $i * 1.9;
            $this->assertSame($a->fbm($x, $y), $b->fbm($x, $y), 'same seed → the same noise');
        }
    }

    public function test_different_seeds_make_different_noise(): void
    {
        $a = new FractalNoise(1);
        $b = new FractalNoise(2);

        $differs = false;
        for ($i = 0; $i < 20; $i++) {
            if ($a->fbm($i * 2.3, $i * 4.1) !== $b->fbm($i * 2.3, $i * 4.1)) {
                $differs = true;
                break;
            }
        }

        $this->assertTrue($differs, 'a different seed yields different terrain');
    }

    public function test_output_stays_within_range(): void
    {
        $noise = new FractalNoise(7);

        $min = INF;
        $max = -INF;
        for ($x = 0; $x < 80; $x++) {
            for ($y = 0; $y < 50; $y++) {
                $value = $noise->fbm($x, $y);
                $min = min($min, $value);
                $max = max($max, $value);
            }
        }

        $this->assertGreaterThanOrEqual(-1.0, $min);
        $this->assertLessThanOrEqual(1.0, $max);
    }
}
