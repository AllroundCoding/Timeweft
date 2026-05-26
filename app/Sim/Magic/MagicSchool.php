<?php

namespace App\Sim\Magic;

/**
 * The school a current belongs to — set by its source, changed by a school-convert transform, and carried
 * into the world-effect at the sink. A fixed palette for the spell-graph core; a world's *available*
 * schools are generated from its thaumic field and pantheon in a later ticket (the palette generator).
 */
enum MagicSchool: string
{
    case Fire = 'fire';
    case Water = 'water';
    case Earth = 'earth';
    case Air = 'air';
    case Life = 'life';
    case Death = 'death';
    case Mind = 'mind';
    case Divine = 'divine';
}
