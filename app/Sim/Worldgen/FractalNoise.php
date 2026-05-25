<?php

namespace App\Sim\Worldgen;

/**
 * Deterministic fractal value noise (fBm; TWT-262) — layered, hash-based lattice noise that adds organic
 * relief to the otherwise dead-flat tectonic substrate. Pure and framework-free: a fixed function of its
 * integer seed and coordinates (a 32-bit integer hash, no floating-point drift between octaves), so the
 * same world seed yields the same hills.
 */
final class FractalNoise
{
    private const OCTAVES = 5;

    private const PERSISTENCE = 0.5;   // amplitude falloff per octave

    private const LACUNARITY = 2.0;    // frequency growth per octave

    private const MASK = 0xFFFFFFFF;   // keep the hash in 32 bits so products never spill into floats

    public function __construct(
        private readonly int $seed,
        private readonly float $frequency = 0.04,
    ) {}

    /** Fractal Brownian motion at (x, y), in roughly [-1, 1]. */
    public function fbm(float $x, float $y): float
    {
        $sum = 0.0;
        $norm = 0.0;
        $amplitude = 1.0;
        $frequency = $this->frequency;
        for ($octave = 0; $octave < self::OCTAVES; $octave++) {
            $sum += $amplitude * $this->value($x * $frequency, $y * $frequency, $octave);
            $norm += $amplitude;
            $amplitude *= self::PERSISTENCE;
            $frequency *= self::LACUNARITY;
        }

        return $norm > 0.0 ? ($sum / $norm) * 2.0 - 1.0 : 0.0; // value() is [0,1] → remap to [-1,1]
    }

    /** Smooth value noise on the integer lattice, in [0, 1]. */
    private function value(float $x, float $y, int $octave): float
    {
        $x0 = (int) floor($x);
        $y0 = (int) floor($y);
        $fx = self::fade($x - $x0);
        $fy = self::fade($y - $y0);

        $n00 = $this->lattice($x0, $y0, $octave);
        $n10 = $this->lattice($x0 + 1, $y0, $octave);
        $n01 = $this->lattice($x0, $y0 + 1, $octave);
        $n11 = $this->lattice($x0 + 1, $y0 + 1, $octave);

        $nx0 = $n00 + ($n10 - $n00) * $fx;
        $nx1 = $n01 + ($n11 - $n01) * $fx;

        return $nx0 + ($nx1 - $nx0) * $fy;
    }

    /** A stable pseudo-random value in [0, 1] for one lattice point, from a 32-bit integer avalanche hash. */
    private function lattice(int $x, int $y, int $octave): float
    {
        $h = ($this->seed + $octave * 0x9E3779B1) & self::MASK;
        $h = ($h ^ ($x * 0x85EBCA77)) & self::MASK;
        $h = ($h ^ ($y * 0xC2B2AE3D)) & self::MASK;
        $h = ($h ^ ($h >> 15)) & self::MASK;
        $h = ($h * 0x27D4EB2F) & self::MASK;
        $h = ($h ^ ($h >> 13)) & self::MASK;

        return $h / self::MASK;
    }

    /** Perlin's smootherstep — a soft S-curve so the lattice interpolation has no creases. */
    private static function fade(float $t): float
    {
        return $t * $t * $t * ($t * ($t * 6.0 - 15.0) + 10.0);
    }
}
