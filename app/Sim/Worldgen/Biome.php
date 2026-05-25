<?php

namespace App\Sim\Worldgen;

/**
 * A coarse biome class (design doc 13; TWT-132) — the living surface a cell wears, classified from its
 * temperature, precipitation, and whether it is above the waterline. Deliberately a small set: enough to
 * read a world map at a glance and to drive fertility/scarcity, not a full Holdridge lattice (that detail
 * graduates later). {@see ClimateGenerator::classify()} assigns it.
 */
enum Biome: string
{
    case Ocean = 'ocean';
    case Ice = 'ice';
    case Tundra = 'tundra';
    case Desert = 'desert';
    case Shrubland = 'shrubland';
    case Grassland = 'grassland';
    case Forest = 'forest';
    case Rainforest = 'rainforest';
}
