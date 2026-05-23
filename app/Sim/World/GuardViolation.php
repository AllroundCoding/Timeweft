<?php

namespace App\Sim\World;

/**
 * A breach of a world-guider invariant (TWT-90). `corrected` records whether the guard could clamp the
 * state back within bounds (a bounded scalar) or could only flag it (e.g. an overcrowded population,
 * which the engine — not the guard — must resolve).
 */
final class GuardViolation
{
    public function __construct(
        public readonly string $rule,
        public readonly string $detail,
        public readonly bool $corrected,
    ) {}
}
