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

    /** Extra effectiveness lost in a fully-unpaid year — insolvency withers an institution beyond mere age (TWT-116). */
    private const INSOLVENCY_PENALTY = 0.2;

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

        // Upkeep is drawn from the granary — but only what's there. A settlement too poor to feed its
        // institution lets it wither: unpaid upkeep costs standing on top of ossification (TWT-116;
        // Tainter — complexity is shed when its returns fall below its cost). At a full granary this is
        // unchanged, so a viable seed run is unaffected.
        $paid = $world->village->stockpile->withdraw('food', self::UPKEEP_FOOD_PER_YEAR);
        $insolvent = $paid < self::UPKEEP_FOOD_PER_YEAR;
        if ($insolvent) {
            $institution->ossify(self::INSOLVENCY_PENALTY * (self::UPKEEP_FOOD_PER_YEAR - $paid) / self::UPKEEP_FOOD_PER_YEAR);
        }

        if ($institution->hasOssified(self::COLLAPSE_EFFECTIVENESS)) {
            $village = $world->village;
            $village->institution = null;
            $village->underpreparedYears = 0;

            // Why it fell — starved out, or simply ossified — ties the causal layer (TWT-27).
            [$reason, $factor] = $insolvent
                ? ['no longer fed by a settlement too poor to keep it', 'insolvency']
                : ['ossified and extracting more than it returns', 'ossification'];
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — the %s, %s, collapses; %s falls back on its own cohesion.',
                $date->dayOfMonth, $date->monthName, $date->year, $institution->name, $reason, $village->name,
            ), 'institution-collapsed', [], $village->institutionEventId !== null ? [$village->institutionEventId] : [], [$factor]);
            $village->institutionEventId = null;
            $village->underpreparedEventIds = [];
        }
    }
}
