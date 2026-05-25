<?php

namespace App\Sim\Culture;

/**
 * The shape a remembered event takes as it passes into legend (TWT-143).
 */
enum LegendKind: string
{
    /** An origin — a settlement, an institution, or a people's beginning. */
    case FoundingMyth = 'founding';

    /** A named individual's deeds — a war led, a calamity survived, a mastery won. */
    case HeroTale = 'hero';

    /** A plague, famine, fall, or other calamity — the cautionary memory. */
    case Catastrophe = 'catastrophe';

    /** A victory, an alliance, a deliverance — the celebrated memory. */
    case Triumph = 'triumph';
}
