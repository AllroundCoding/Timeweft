<?php

namespace App\Sim\Economy;

/**
 * A good as a small stat vector — the same composable pattern as an agent's traits, applied to the
 * things a settlement produces, eats, and trades (design doc 12). Nutrition feeds the diet/health
 * model, value feeds pricing and trade, and perishability governs how fast stores spoil. Today
 * goods are a catalog beside the scalar economy; recipes and nutrition build on them next.
 */
final class Good
{
    public function __construct(
        public readonly string $name,
        public readonly float $nutrition,
        public readonly float $value,
        public readonly float $perishability,
    ) {}
}
