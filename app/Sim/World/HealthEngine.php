<?php

namespace App\Sim\World;

/**
 * Health as a daily condition (design doc 05): each agent carries a `sickness` need that climbs
 * with the things that actually make people ill — crowding past carrying capacity, the scarcity
 * of a famine, and the frailty of old age — and recedes with recovery when life is good. Sickness
 * then compounds mortality in the EmergenceEngine, giving death causes beyond age and storms.
 *
 * Deterministic (no RNG); sharp disease *events* arrive through the ShockEngine's plague.
 */
final class HealthEngine
{
    private const BASE_EXPOSURE = 0.04;     // background illness pressure per day

    private const RECOVERY = 0.12;          // daily recovery when conditions are good

    private const FRAILTY_AGE = 45;         // age past which frailty starts to accrue

    private const FRAILTY_PER_YEAR = 0.012; // added daily sickness per year beyond that

    private const CROWDING_FACTOR = 0.6;    // per unit of population over carrying capacity

    private const SCARCITY_FACTOR = 0.12;   // per unit of starvation pressure (famine)

    public static function runDay(World $world, int $tick): void
    {
        $village = $world->village;
        $living = $world->livingAgents();
        $population = count($living);
        if ($population === 0) {
            return;
        }

        $crowding = $village->carryingCapacity > 0
            ? max(0.0, $population / $village->carryingCapacity - 1.0)
            : 0.0;
        $scarcity = max(0.0, $village->starvationFactor - 1.0);
        $environmental = self::BASE_EXPOSURE + $crowding * self::CROWDING_FACTOR + $scarcity * self::SCARCITY_FACTOR;

        foreach ($living as $agent) {
            $sickness = $agent->needs['sickness'] ?? null;
            if ($sickness === null) {
                continue;
            }

            $frailty = max(0, $agent->ageInYears($tick) - self::FRAILTY_AGE) * self::FRAILTY_PER_YEAR;
            $delta = $environmental + $frailty - self::RECOVERY;
            $sickness->value = max(0.0, min(100.0, $sickness->value + $delta));
        }
    }
}
