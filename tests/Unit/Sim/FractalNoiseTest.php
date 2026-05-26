<?php

namespace Tests\Unit\Sim;

use App\Sim\Worldgen\FractalNoise;
use PHPUnit\Framework\TestCase;

/**
 * TWT-77/262 — deterministic 3D spherical fractal noise. Same seed → same value everywhere; different
 * seeds diverge; output stays in [-1, 1]; and the sphere projection wraps without a seam at the
 * antimeridian (x = 0 and x = width are the same longitude).
 */
class FractalNoiseTest extends TestCase
{
    private const W = 256.0;

    private const H = 128.0;

    public function test_it_is_deterministic(): void
    {
        $a = new FractalNoise(12345, 0.05);
        $b = new FractalNoise(12345, 0.05);

        for ($i = 0; $i < 20; $i++) {
            $x = $i * 7.3;
            $y = $i * 3.1;
            $this->assertSame(
                $a->fbmSpherical($x, $y, self::W, self::H),
                $b->fbmSpherical($x, $y, self::W, self::H),
                'same seed → the same noise',
            );
        }
    }

    public function test_different_seeds_make_different_noise(): void
    {
        $a = new FractalNoise(1, 0.05);
        $b = new FractalNoise(2, 0.05);

        $differs = false;
        for ($i = 0; $i < 20; $i++) {
            if ($a->fbmSpherical($i * 5.0, $i * 2.0, self::W, self::H) !== $b->fbmSpherical($i * 5.0, $i * 2.0, self::W, self::H)) {
                $differs = true;
                break;
            }
        }

        $this->assertTrue($differs, 'a different seed yields different terrain');
    }

    public function test_output_stays_within_range(): void
    {
        $noise = new FractalNoise(7, 0.05);

        $min = INF;
        $max = -INF;
        for ($x = 0; $x < 64; $x++) {
            for ($y = 0; $y < 48; $y++) {
                $value = $noise->fbmSpherical((float) $x, (float) $y, self::W, self::H);
                $min = min($min, $value);
                $max = max($max, $value);
            }
        }

        $this->assertGreaterThanOrEqual(-1.0, $min);
        $this->assertLessThanOrEqual(1.0, $max);
    }

    public function test_it_wraps_seamlessly_at_the_antimeridian(): void
    {
        $noise = new FractalNoise(99, 0.05);

        for ($y = 0; $y < 128; $y += 16) {
            $this->assertEqualsWithDelta(
                $noise->fbmSpherical(0.0, (float) $y, self::W, self::H),
                $noise->fbmSpherical(self::W, (float) $y, self::W, self::H),
                1.0e-9,
                'longitude wraps without a seam',
            );
        }
    }
}
