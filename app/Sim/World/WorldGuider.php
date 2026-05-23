<?php

namespace App\Sim\World;

/**
 * The world guider (design doc 15; TWT-90): a runtime guardrail that keeps generation within its rules
 * and the bounds of plausibility — the engine's immune system. This is the **hard-rules tier**:
 * deterministic, always-on, declared invariants. Bounded scalars (culture dims, needs, diet, aid) are
 * clamped back within range; a degenerate population (far past what the land can feed) is flagged for
 * the engine to resolve, since the guard must not delete people. It draws no RNG and, because every
 * value the engine produces is already in range, the normal seeded run trips nothing — so wiring it
 * in leaves that run byte-identical.
 *
 * Pluggable like the story director: this rules-only tier is the reproducible safety floor; an
 * optional local LLM tier (M7) would *explain* subtler implausibilities in plain language without
 * touching the deterministic state. It is also the referee for the director (TWT-89): even an
 * authored intervention must satisfy these invariants.
 */
final class WorldGuider
{
    /** Beyond this multiple of carrying capacity, a population is degenerately overcrowded (flag-only). */
    private const POP_OVERSHOOT_BAND = 5.0;

    /**
     * Check every settlement against the invariants, clamping what can be clamped and flagging the rest.
     *
     * @return list<GuardViolation>
     */
    public static function inspect(World $world, int $tick): array
    {
        $violations = [];
        foreach ($world->villages as $village) {
            self::guardCulture($village, $violations);
            self::guardBoundedScalars($village, $violations);
            self::guardNeeds($village, $violations);
            self::guardPopulation($village, $violations);
        }

        return $violations;
    }

    /** @param  list<GuardViolation>  $violations */
    private static function guardCulture(Village $village, array &$violations): void
    {
        foreach ($village->culture->vector() as $dimension => $value) {
            if ($value < 0.0 || $value > 100.0) {
                $village->culture = $village->culture->clamped();
                $village->baselineCohesion = $village->culture->baselineCohesion();
                $violations[] = new GuardViolation('culture-out-of-range', "{$village->name} {$dimension}={$value}", true);

                return; // one clamp fixes the whole vector
            }
        }
    }

    /** @param  list<GuardViolation>  $violations */
    private static function guardBoundedScalars(Village $village, array &$violations): void
    {
        $unit = ['dietQuality' => $village->dietQuality, 'mutualAid' => $village->mutualAid];
        foreach ($unit as $name => $value) {
            if ($value < 0.0 || $value > 1.0) {
                $village->$name = max(0.0, min(1.0, $value));
                $violations[] = new GuardViolation('out-of-range', "{$village->name} {$name}={$value}", true);
            }
        }
        if ($village->starvationFactor < 1.0) {
            $violations[] = new GuardViolation('out-of-range', "{$village->name} starvationFactor={$village->starvationFactor}", true);
            $village->starvationFactor = 1.0;
        }
    }

    /** @param  list<GuardViolation>  $violations */
    private static function guardNeeds(Village $village, array &$violations): void
    {
        foreach ($village->livingAgents() as $agent) {
            foreach ($agent->needs as $key => $need) {
                if ($need->value < 0.0 || $need->value > 100.0) {
                    $need->value = max(0.0, min(100.0, $need->value));
                    $violations[] = new GuardViolation('need-out-of-range', "agent {$agent->id} {$key}", true);
                }
            }
        }
    }

    /** @param  list<GuardViolation>  $violations */
    private static function guardPopulation(Village $village, array &$violations): void
    {
        $population = count($village->livingAgents());
        if ($village->carryingCapacity > 0 && $population > self::POP_OVERSHOOT_BAND * $village->carryingCapacity) {
            // The guard cannot cull people; it flags the implausibility for the engine (or a human) to resolve.
            $violations[] = new GuardViolation(
                'population-overshoot',
                "{$village->name} holds {$population}, far past what its land sustains (~{$village->carryingCapacity})",
                false,
            );
        }
    }
}
