<?php

namespace App\Sim\World;

/**
 * A composable drive attached to an agent. Value 0 = satisfied, 100 = critical.
 * Needs are components, not hardcoded fields — a "god among men" simply has none.
 */
final class Need
{
    public function __construct(
        public readonly string $name,
        public float $value,
        public readonly float $risePerTick,
    ) {}

    public function advance(int $ticks = 1, float $multiplier = 1.0): void
    {
        $this->value = min(100.0, $this->value + $this->risePerTick * $ticks * $multiplier);
    }

    public function satisfy(float $amount = 100.0): void
    {
        $this->value = max(0.0, $this->value - $amount);
    }
}
