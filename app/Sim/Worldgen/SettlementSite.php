<?php

namespace App\Sim\Worldgen;

/**
 * A settlement the worldgen sited on the map (TWT-82): where it sits, how good the site is, and the tier
 * it grew to. Emerges from the geography — never hand-placed. Pure data; a {@see SettlementSiter} produces
 * the list for a world.
 */
final readonly class SettlementSite
{
    public function __construct(
        public int $x,
        public int $y,
        public float $suitability,
        public SettlementTier $tier,
    ) {}
}
