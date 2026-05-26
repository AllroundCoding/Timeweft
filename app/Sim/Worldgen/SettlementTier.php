<?php

namespace App\Sim\Worldgen;

/**
 * How big a sited settlement grows (TWT-82) — set by its hinterland's fertility and its trade position.
 * A river-mouth on fertile land becomes a city; an isolated dry site stays a hamlet.
 */
enum SettlementTier: string
{
    case Hamlet = 'hamlet';
    case Village = 'village';
    case Town = 'town';
    case City = 'city';
}
