<?php

namespace App\Sim\Worldgen;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A fast, seeded 3D Perlin noise generator for seamless spherical planet mapping (TWT-77/262).
 *
 * Sampling on a 3D sphere ({@see fbmSpherical()}) removes the east-west seam and the polar stretching a
 * flat 2D lattice would show. Deterministic: the permutation table is shuffled with the project's seeded
 * {@see Randomizer} (never the global mt_rand()), so the same seed yields the same noise with no global
 * side effects.
 *
 * Hot-path notes (TWT-263): the sphere projection is split out as {@see sphereCoords()} so a caller that
 * samples several noise fields at one cell projects once and reuses the point via {@see fbm3D()} instead
 * of re-deriving it per field; and {@see noise3D()} inlines the fade/lerp arithmetic to drop the
 * per-octave function-call overhead. Both keep the exact arithmetic, so the noise is byte-identical.
 */
final class FractalNoise
{
    private float $frequency;

    /** @var array<int, int> doubled permutation table, shuffled deterministically from the seed */
    private array $perm = [];

    private const OCTAVES = 6;

    private const GAIN = 0.5;

    private const LACUNARITY = 2.0;

    public function __construct(int $seed, float $frequency)
    {
        $this->frequency = $frequency;

        // Stable permutation table from the seed — shuffled with a local seeded Randomizer (Mt19937), not
        // the global mt_rand(), so worldgen stays deterministic and free of global side effects.
        $randomizer = new Randomizer(new Mt19937($seed));
        $p = range(0, 255);

        // Fisher-Yates shuffle
        for ($i = 255; $i > 0; $i--) {
            $j = $randomizer->getInt(0, $i);
            [$p[$i], $p[$j]] = [$p[$j], $p[$i]];
        }

        // Double it to avoid overflow in lookups
        for ($i = 0; $i < 512; $i++) {
            $this->perm[$i] = $p[$i & 255];
        }
    }

    /**
     * Project a flat map cell onto the 3D sphere — the seam- and pole-free point that noise is sampled at.
     * A pure function of the cell and map size, independent of any noise instance, so a caller sampling
     * several fields at one cell computes this once and feeds {@see fbm3D()}.
     *
     * @return array{0: float, 1: float, 2: float} the (nx, ny, nz) point on the sphere
     */
    public static function sphereCoords(float $x, float $y, float $width, float $height): array
    {
        // Flat X/Y to Longitude (0 to 2PI) and Latitude (-PI/2 to PI/2), then onto a sphere.
        $lon = ($x / $width) * 2.0 * M_PI;
        $lat = ($y / $height) * M_PI - (M_PI / 2.0);
        $radius = $width / (2.0 * M_PI);

        return [
            cos($lat) * cos($lon) * $radius,
            cos($lat) * sin($lon) * $radius,
            sin($lat) * $radius,
        ];
    }

    /**
     * Projects 2D map coordinates onto a 3D sphere and samples noise.
     * Completely eliminates polar stretching and wrapping seams.
     */
    public function fbmSpherical(float $x, float $y, float $width, float $height, float $seedOffset = 0.0): float
    {
        [$nx, $ny, $nz] = self::sphereCoords($x, $y, $width, $height);

        return $this->fbm3D($nx, $ny, $nz, $seedOffset);
    }

    /**
     * Sample fractal Brownian motion at a pre-projected sphere point ({@see sphereCoords()}). The
     * seedOffset forces the 3D sampling into a different sector of the noise universe, giving uncorrelated
     * lookups (e.g. an X-warp vs a Y-warp) without breaking the sphere.
     */
    public function fbm3D(float $nx, float $ny, float $nz, float $seedOffset = 0.0): float
    {
        $amplitude = 1.0;
        $freq = $this->frequency;
        $total = 0.0;
        $maxValue = 0.0;

        for ($i = 0; $i < self::OCTAVES; $i++) {
            $total += $this->noise3D($nx * $freq + $seedOffset, $ny * $freq - $seedOffset, $nz * $freq + $seedOffset) * $amplitude;
            $maxValue += $amplitude;
            $amplitude *= self::GAIN;
            $freq *= self::LACUNARITY;
        }

        return $total / $maxValue;
    }

    /**
     * Classic 3D Perlin noise. The fade curves and the trilinear lerp are inlined (TWT-263) — the same
     * arithmetic the {@see grad3()} corners feed, just without the per-call overhead that dominated the
     * worldgen hot loop.
     */
    private function noise3D(float $x, float $y, float $z): float
    {
        $xi = (int) floor($x) & 255;
        $yi = (int) floor($y) & 255;
        $zi = (int) floor($z) & 255;

        $x -= floor($x);
        $y -= floor($y);
        $z -= floor($z);

        // Fade curves (6t^5 - 15t^4 + 10t^3), inlined.
        $u = $x * $x * $x * ($x * ($x * 6.0 - 15.0) + 10.0);
        $v = $y * $y * $y * ($y * ($y * 6.0 - 15.0) + 10.0);
        $w = $z * $z * $z * ($z * ($z * 6.0 - 15.0) + 10.0);

        $p = $this->perm;
        $A = $p[$xi] + $yi;
        $AA = $p[$A] + $zi;
        $AB = $p[$A + 1] + $zi;
        $B = $p[$xi + 1] + $yi;
        $BA = $p[$B] + $zi;
        $BB = $p[$B + 1] + $zi;

        // Gradient at each of the cube's eight corners. grad3 is inlined here (TWT-263) — its exact
        // `$h<8?…` / `$h<4?…` branch arithmetic per corner, so byte-identical, but without eight function
        // calls per octave, which dominated the interpreted hot loop (no JIT in the CLI).
        $x1 = $x - 1.0;
        $y1 = $y - 1.0;
        $z1 = $z - 1.0;

        $h = $p[$AA] & 15;
        $gu = $h < 8 ? $x : $y;
        $gv = $h < 4 ? $y : ($h === 12 || $h === 14 ? $x : $z);
        $g000 = (($h & 1) === 0 ? $gu : -$gu) + (($h & 2) === 0 ? $gv : -$gv);

        $h = $p[$BA] & 15;
        $gu = $h < 8 ? $x1 : $y;
        $gv = $h < 4 ? $y : ($h === 12 || $h === 14 ? $x1 : $z);
        $g100 = (($h & 1) === 0 ? $gu : -$gu) + (($h & 2) === 0 ? $gv : -$gv);

        $h = $p[$AB] & 15;
        $gu = $h < 8 ? $x : $y1;
        $gv = $h < 4 ? $y1 : ($h === 12 || $h === 14 ? $x : $z);
        $g010 = (($h & 1) === 0 ? $gu : -$gu) + (($h & 2) === 0 ? $gv : -$gv);

        $h = $p[$BB] & 15;
        $gu = $h < 8 ? $x1 : $y1;
        $gv = $h < 4 ? $y1 : ($h === 12 || $h === 14 ? $x1 : $z);
        $g110 = (($h & 1) === 0 ? $gu : -$gu) + (($h & 2) === 0 ? $gv : -$gv);

        $h = $p[$AA + 1] & 15;
        $gu = $h < 8 ? $x : $y;
        $gv = $h < 4 ? $y : ($h === 12 || $h === 14 ? $x : $z1);
        $g001 = (($h & 1) === 0 ? $gu : -$gu) + (($h & 2) === 0 ? $gv : -$gv);

        $h = $p[$BA + 1] & 15;
        $gu = $h < 8 ? $x1 : $y;
        $gv = $h < 4 ? $y : ($h === 12 || $h === 14 ? $x1 : $z1);
        $g101 = (($h & 1) === 0 ? $gu : -$gu) + (($h & 2) === 0 ? $gv : -$gv);

        $h = $p[$AB + 1] & 15;
        $gu = $h < 8 ? $x : $y1;
        $gv = $h < 4 ? $y1 : ($h === 12 || $h === 14 ? $x : $z1);
        $g011 = (($h & 1) === 0 ? $gu : -$gu) + (($h & 2) === 0 ? $gv : -$gv);

        $h = $p[$BB + 1] & 15;
        $gu = $h < 8 ? $x1 : $y1;
        $gv = $h < 4 ? $y1 : ($h === 12 || $h === 14 ? $x1 : $z1);
        $g111 = (($h & 1) === 0 ? $gu : -$gu) + (($h & 2) === 0 ? $gv : -$gv);

        // Trilinear interpolation (lerp inlined): along x, then y, then z — the exact nesting of the
        // original lerp() calls.
        $x00 = $g000 + $u * ($g100 - $g000);
        $x10 = $g010 + $u * ($g110 - $g010);
        $x01 = $g001 + $u * ($g101 - $g001);
        $x11 = $g011 + $u * ($g111 - $g011);

        $y0 = $x00 + $v * ($x10 - $x00);
        $y1 = $x01 + $v * ($x11 - $x01);

        return $y0 + $w * ($y1 - $y0);
    }
}
