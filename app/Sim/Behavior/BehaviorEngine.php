<?php

namespace App\Sim\Behavior;

use App\Sim\Time\TharadiDate;
use App\Sim\World\Agent;

/** Derives what an agent is doing at a tick, and applies the effects on its needs. */
final class BehaviorEngine
{
    private const HUNGER_OVERRIDE = 70.0;

    /** @var ?list<array{when: \Closure, then: \Closure}> the priority stack, built once and cached */
    private static ?array $priorityStack = null;

    public static function derive(Agent $agent, TharadiDate $date, bool $isFestival, bool $contributing = false): Activity
    {
        foreach (self::priorityStack() as $rule) {
            if (($rule['when'])($agent, $date, $isFestival, $contributing)) {
                return ($rule['then'])($agent, $date, $isFestival, $contributing);
            }
        }

        return self::routineActivity($date); // the routine floor always matches; this is unreachable
    }

    /**
     * The behavior priority stack (design doc 04): an ordered list of guard → activity rules. The
     * first guard that matches wins, and the routine layer is the always-true floor. Expressed as
     * data so the stack can be reordered or extended — a festival layer today, a player-input layer
     * tomorrow (doc 16) — without re-threading an if-chain. A guard may declare only the parameters
     * it reads; the extra arguments passed at the call site are ignored.
     *
     * @return list<array{when: \Closure, then: \Closure}>
     */
    private static function priorityStack(): array
    {
        return self::$priorityStack ??= [
            // A festival takes the daylight hours.
            [
                'when' => static fn (Agent $a, TharadiDate $d, bool $isFestival): bool => $isFestival && $d->hour >= 8 && $d->hour < 20,
                'then' => static fn (): Activity => Activity::Celebrating,
            ],
            // Acute hunger overrides the routine.
            [
                'when' => static fn (Agent $a): bool => $a->needs['hunger']->value >= self::HUNGER_OVERRIDE,
                'then' => static fn (): Activity => Activity::Eating,
            ],
            // The base routine — work aimed at a communal project surfaces as Contributing.
            [
                'when' => static fn (): bool => true,
                'then' => static function (Agent $a, TharadiDate $d, bool $isFestival, bool $contributing): Activity {
                    $routine = self::routineActivity($d);

                    return $routine === Activity::Working && $contributing ? Activity::Contributing : $routine;
                },
            ],
        ];
    }

    /** The base routine layer: hour-of-day + season, ignoring needs and festivals. */
    public static function routineActivity(TharadiDate $date): Activity
    {
        $h = $date->hour;

        if ($date->season === 'Sandstorm' && $h >= 11 && $h < 15) {
            return Activity::Sheltering; // wait out the lethal midday heat
        }
        if ($h >= 22 || $h < 6) {
            return Activity::Sleeping;
        }
        if ($h === 6 || $h === 12 || $h === 18) {
            return Activity::Eating;
        }
        if (($h >= 7 && $h < 12) || ($h >= 13 && $h < 18)) {
            return Activity::Working;
        }

        return Activity::Socializing;
    }

    public static function applyEffects(Agent $agent, Activity $activity, float $seasonMultiplier): void
    {
        $hunger = $agent->needs['hunger'];
        $energy = $agent->needs['energy'];

        switch ($activity) {
            case Activity::Sleeping:
                $energy->satisfy($energy->risePerTick * 3.0);
                $hunger->advance(1, $seasonMultiplier * 0.5);
                break;
            case Activity::Eating:
                $hunger->satisfy(60.0);
                $energy->advance(1, $seasonMultiplier);
                break;
            case Activity::Working:
            case Activity::Contributing: // contributing is work, just aimed at a communal project
                $hunger->advance(1, $seasonMultiplier * 1.2);
                $energy->advance(1, $seasonMultiplier * 1.2);
                break;
            case Activity::Celebrating:
                $hunger->advance(1, $seasonMultiplier * 1.2);
                $energy->advance(1, $seasonMultiplier);
                break;
            case Activity::Resting:
            case Activity::Sheltering:
            case Activity::Socializing:
                $hunger->advance(1, $seasonMultiplier);
                $energy->advance(1, $seasonMultiplier * 0.6);
                break;
        }
    }
}
