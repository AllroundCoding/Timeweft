<?php

namespace App\Sim\World;

use App\Sim\Time\TharadiDate;

/**
 * Inter-settlement relations (design doc 05/06; TWT-125): every pair of settlements holds a standing
 * — hostile (0) ↔ allied (1) — that drifts each year toward what their material and cultural
 * conditions would warrant. Kinship draws them together (a shared culture trusts its own); proximity
 * under shared scarcity drives them apart (close, hungry neighbours compete). Relations are
 * path-dependent: an enmity, once fallen into, takes years to mend.
 *
 * The standing gates cooperation across the map: sworn enemies neither trade (TradeEngine reads
 * {@see hostile}) nor send caravans (CaravanEngine), and migrants won't flee *into* a hostile settlement
 * (MigrationEngine); enmity instead breaks into raids and open war (WarEngine), while {@see cohesion} —
 * standing scaled by kinship — sets how readily neighbours aid one another (DistressEngine). World-level,
 * deterministic, and RNG-free — a no-op below two settlements, so the single-settlement run is byte-identical.
 */
final class RelationsEngine
{
    private const NEUTRAL = 0.5;

    private const HOSTILE_BELOW = 0.3;

    private const ALLIED_ABOVE = 0.7;

    private const DRIFT_RATE = 0.1; // fraction of the way toward the warranted standing, per year

    private const KINSHIP_WEIGHT = 0.5; // how strongly a shared culture pulls toward amity

    private const COMPETITION_WEIGHT = 0.6; // how strongly proximate, shared scarcity pulls toward enmity

    private const PROXIMITY_HALF = 150.0; // map distance at which the competition pull halves

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // relations shift over years, settled at the turn of the year
        }
        $villages = $world->villages;
        $count = count($villages);
        if ($count < 2) {
            return; // no one to have relations with
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($world->crossRegionBarrier && RegionPartition::sameRegion($villages[$i], $villages[$j])) {
                    continue; // intra-region standing already drifted inside the region (TWT-112)
                }
                self::settle($world, $villages[$i], $villages[$j], $tick, $date);
            }
        }
    }

    /** The standing between two settlements (0 hostile .. 1 allied); neutral until they have a history. */
    public static function standing(World $world, Village $a, Village $b): float
    {
        return $world->relations[$a->pairKey($b)] ?? self::NEUTRAL;
    }

    /** Are these two settlements hostile enough to refuse each other — embargo trade, bar refuge? */
    public static function hostile(World $world, Village $a, Village $b): bool
    {
        return self::standing($world, $a, $b) < self::HOSTILE_BELOW;
    }

    /**
     * The cooperation strength between two settlements (0..1; TWT-52) — the cross-settlement counterpart
     * of a settlement's own size-decayed cohesion ({@see Village::cohesion}, TWT-10). Their standing sets
     * the ceiling (rivals barely cooperate, allies readily) and cultural kinship scales it (kindred
     * peoples cooperate more smoothly than strangers at the same standing). Allied kin cohere fully;
     * rivals hardly at all.
     */
    public static function cohesion(World $world, Village $a, Village $b): float
    {
        $kinship = 1.0 - self::culturalDistance($a, $b);

        return self::standing($world, $a, $b) * $kinship;
    }

    /** Drift one pair's standing toward what their conditions warrant, and chronicle a crossing into enmity or alliance. */
    private static function settle(World $world, Village $a, Village $b, int $tick, TharadiDate $date): void
    {
        $key = $a->pairKey($b);
        $before = $world->relations[$key] ?? self::NEUTRAL;
        $target = self::warrantedStanding($a, $b);
        $after = $before + ($target - $before) * self::DRIFT_RATE;
        $world->relations[$key] = $after;

        if ($before >= self::HOSTILE_BELOW && $after < self::HOSTILE_BELOW) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s and %s fall to enmity.',
                $date->dayOfMonth, $date->monthName, $date->year, $a->name, $b->name,
            ), 'relations-enmity', [], [], ['enmity']);
        } elseif ($before < self::HOSTILE_BELOW && $after >= self::HOSTILE_BELOW) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s and %s make peace.',
                $date->dayOfMonth, $date->monthName, $date->year, $a->name, $b->name,
            ), 'relations-peace', [], [], ['peace']);
        } elseif ($before < self::ALLIED_ABOVE && $after >= self::ALLIED_ABOVE) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s and %s forge an alliance.',
                $date->dayOfMonth, $date->monthName, $date->year, $a->name, $b->name,
            ), 'relations-alliance', [], [], ['alliance']);
        }
    }

    /** The standing two settlements' conditions warrant: kinship lifts it, proximate shared scarcity sinks it. */
    private static function warrantedStanding(Village $a, Village $b): float
    {
        $kinship = 1.0 - self::culturalDistance($a, $b); // 1 = same culture, 0 = wholly foreign
        $proximity = self::PROXIMITY_HALF / (self::PROXIMITY_HALF + $a->distanceTo($b));
        $competition = $proximity * (MigrationEngine::pushPressure($a) + MigrationEngine::pushPressure($b)) / 2.0;

        $warranted = self::NEUTRAL
            + self::KINSHIP_WEIGHT * ($kinship - 0.5)
            - self::COMPETITION_WEIGHT * $competition;

        return max(0.0, min(1.0, $warranted));
    }

    /** Normalized 0..1 distance between two settlements' culture vectors (0 identical, 1 maximally foreign). */
    private static function culturalDistance(Village $a, Village $b): float
    {
        $va = $a->culture->vector();
        $vb = $b->culture->vector();
        $sumSquares = 0.0;
        foreach ($va as $dimension => $value) {
            $delta = $value - ($vb[$dimension] ?? 0.0);
            $sumSquares += $delta * $delta;
        }
        $maxDistance = sqrt(count($va)) * 100.0; // each dim spans 0..100

        return $maxDistance > 0.0 ? min(1.0, sqrt($sumSquares) / $maxDistance) : 0.0;
    }
}
