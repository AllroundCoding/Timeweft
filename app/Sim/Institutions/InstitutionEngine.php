<?php

namespace App\Sim\Institutions;

use App\Sim\Time\TharadiDate;
use App\Sim\World\World;

/**
 * The fall half of rise-and-fall (design doc 07). Once a year the standing
 * institution ossifies (delivers less of its mandate) and draws its upkeep from
 * the granary. When it has ossified past the point of returning more cooperation
 * than it costs, it collapses: the settlement sheds it and falls back on organic
 * cohesion, and the deficit clock restarts — so a new institution can later rise.
 *
 * Deterministic (no RNG), so it does not perturb the seeded population.
 */
final class InstitutionEngine
{
    private const OSSIFICATION_PER_YEAR = 0.1;

    private const COLLAPSE_EFFECTIVENESS = 0.4;

    private const UPKEEP_FOOD_PER_YEAR = 50.0;

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        $institution = $world->village->institution;
        if ($institution === null) {
            return;
        }

        // Age once a year, at the turn of the new year.
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return;
        }

        $institution->ossify(self::OSSIFICATION_PER_YEAR);
        $world->village->stockpile->withdraw('food', self::UPKEEP_FOOD_PER_YEAR);

        if ($institution->hasOssified(self::COLLAPSE_EFFECTIVENESS)) {
            $village = $world->village;
            $village->institution = null;
            $village->underpreparedYears = 0;
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — the %s, ossified and extracting more than it returns, collapses; %s falls back on its own cohesion.',
                $date->dayOfMonth, $date->monthName, $date->year, $institution->name, $village->name,
            ), 'institution-collapsed', [], $village->institutionEventId !== null ? [$village->institutionEventId] : [], ['ossification']);
            $village->institutionEventId = null;
            $village->underpreparedEventIds = [];
        }
    }
}
