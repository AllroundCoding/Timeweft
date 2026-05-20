<?php

namespace App\Sim\Behavior;

use App\Sim\Time\TharadiDate;
use App\Sim\World\Agent;

/** Derives what an agent is doing at a tick, and applies the effects on its needs. */
final class BehaviorEngine
{
    private const HUNGER_OVERRIDE = 70.0;

    public static function derive(Agent $agent, TharadiDate $date, bool $isFestival): Activity
    {
        if ($isFestival && $date->hour >= 8 && $date->hour < 20) {
            return Activity::Celebrating;
        }
        if ($agent->needs['hunger']->value >= self::HUNGER_OVERRIDE) {
            return Activity::Eating;
        }

        return self::routineActivity($date);
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
