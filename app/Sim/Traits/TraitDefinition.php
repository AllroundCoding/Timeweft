<?php

namespace App\Sim\Traits;

/**
 * A typed definition of one trait — its kind, generation range, and how strongly it
 * mutates across generations. Replaces the ad-hoc `[min, max]` arrays and the special-
 * cased furColor/heatTolerance handling that used to live in Species/RegionProfile.
 */
final class TraitDefinition
{
    private function __construct(
        public readonly string $key,
        public readonly TraitType $type,
        public readonly float $min,
        public readonly float $max,
        public readonly float $mutation,
    ) {}

    /** Numeric trait drawn uniformly in [min, max] at birth; inherited as the parent average ±mutation. */
    public static function numeric(string $key, float $min, float $max, float $mutation): self
    {
        return new self($key, TraitType::Numeric, $min, $max, $mutation);
    }

    /** Categorical trait whose options come from the region; inherited by picking one parent's value. */
    public static function categorical(string $key): self
    {
        return new self($key, TraitType::Categorical, 0.0, 0.0, 0.0);
    }
}
