<?php

namespace App\Sim\Worldgen;

/**
 * A fast, seeded 3D Perlin Noise generator for true spherical planet mapping.
 */
final class FractalNoise
{
    private float $frequency;
    private array $perm = [];
    private const OCTAVES = 6;
    private const GAIN = 0.5;
    private const LACUNARITY = 2.0;

    public function __construct(int $seed, float $frequency)
    {
        $this->frequency = $frequency;

        // Generate a stable permutation table from the seed
        mt_srand($seed);
        $p = range(0, 255);

        // Fisher-Yates shuffle
        for ($i = 255; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $temp = $p[$i];
            $p[$i] = $p[$j];
            $p[$j] = $temp;
        }

        // Double it to avoid overflow in lookups
        for ($i = 0; $i < 512; $i++) {
            $this->perm[$i] = $p[$i & 255];
        }
    }

    /**
     * Projects 2D map coordinates onto a 3D sphere and samples noise.
     * Completely eliminates polar stretching and wrapping seams.
     */
    public function fbmSpherical(float $x, float $y, float $width, float $height, float $seedOffset = 0.0): float
    {
        // 1. Convert flat X/Y to Longitude (0 to 2PI) and Latitude (-PI/2 to PI/2)
        $lon = ($x / $width) * 2.0 * M_PI;
        $lat = ($y / $height) * M_PI - (M_PI / 2.0);

        // 2. Project onto a 3D sphere
        $radius = $width / (2.0 * M_PI);

        $nx = cos($lat) * cos($lon) * $radius;
        $ny = cos($lat) * sin($lon) * $radius;
        $nz = sin($lat) * $radius;

        // 3. Sample 3D Fractal Brownian Motion
        $amplitude = 1.0;
        $freq = $this->frequency;
        $total = 0.0;
        $maxValue = 0.0;

        for ($i = 0; $i < self::OCTAVES; $i++) {
            // The seedOffset cleanly forces the 3D sampling to happen in a totally different sector
            // of the noise universe, guaranteeing uncorrelated lookups without breaking the sphere!
            $total += $this->noise3D($nx * $freq + $seedOffset, $ny * $freq - $seedOffset, $nz * $freq + $seedOffset) * $amplitude;
            $maxValue += $amplitude;
            $amplitude *= self::GAIN;
            $freq *= self::LACUNARITY;
        }

        return $total / $maxValue;
    }

    /** Classic 3D Perlin Noise */
    private function noise3D(float $x, float $y, float $z): float
    {
        $X = (int)floor($x) & 255;
        $Y = (int)floor($y) & 255;
        $Z = (int)floor($z) & 255;

        $x -= floor($x);
        $y -= floor($y);
        $z -= floor($z);

        $u = $this->fade($x);
        $v = $this->fade($y);
        $w = $this->fade($z);

        $p = $this->perm;
        $A  = $p[$X] + $Y; $AA = $p[$A] + $Z; $AB = $p[$A + 1] + $Z;
        $B  = $p[$X + 1] + $Y; $BA = $p[$B] + $Z; $BB = $p[$B + 1] + $Z;

        return $this->lerp($w,
            $this->lerp($v,
                $this->lerp($u, $this->grad3($p[$AA], $x, $y, $z), $this->grad3($p[$BA], $x - 1, $y, $z)),
                $this->lerp($u, $this->grad3($p[$AB], $x, $y - 1, $z), $this->grad3($p[$BB], $x - 1, $y - 1, $z))
            ),
            $this->lerp($v,
                $this->lerp($u, $this->grad3($p[$AA + 1], $x, $y, $z - 1), $this->grad3($p[$BA + 1], $x - 1, $y, $z - 1)),
                $this->lerp($u, $this->grad3($p[$AB + 1], $x, $y - 1, $z - 1), $this->grad3($p[$BB + 1], $x - 1, $y - 1, $z - 1))
            )
        );
    }

    private function fade(float $t): float { return $t * $t * $t * ($t * ($t * 6.0 - 15.0) + 10.0); }
    private function lerp(float $t, float $a, float $b): float { return $a + $t * ($b - $a); }

    private function grad3(int $hash, float $x, float $y, float $z): float
    {
        $h = $hash & 15;
        $u = $h < 8 ? $x : $y;
        $v = $h < 4 ? $y : ($h === 12 || $h === 14 ? $x : $z);
        return (($h & 1) === 0 ? $u : -$u) + (($h & 2) === 0 ? $v : -$v);
    }
}
