<?php

namespace App\Sim\Projects;

use App\Sim\Institutions\Institution;
use App\Sim\Time\TharadiCalendar;
use App\Sim\Time\TharadiDate;
use App\Sim\World\Agent;
use App\Sim\World\World;

/**
 * Bottom-up steering: a group works toward a shared goal, each member
 * contributing effort by participation weight (cohesion × disposition). This is
 * the StoryDirector pattern democratized — the goal originates in-world.
 *
 * v1 models the recurring communal Sandstorm preparation: a calendar-pinned
 * project opened at the new year for the Sandstorm that begins at Kalimos.
 */
final class ProjectEngine
{
    private const ADULT_AGE = 16;

    private const REQUIRED_PER_CAPITA = 30.0;

    private const SANDSTORM_START_MONTH = 2; // Kalimos = first Sandstorm month

    private const DEFICIT_YEARS_TO_INSTITUTION = 3; // a deficit this persistent stops being a fluke

    private const WAGE_FOR_PARTICIPATION = 2.0; // treasury money to hire one adult for a day

    private const PAID_TO_STRENGTH = 0.12;      // hired effort is a modest supplement, not a deficit-eraser

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        self::maybeOpenSandstormPrep($world, $tick, $date);
        self::contributeDaily($world, $tick);
        self::resolveDue($world, $tick, $date);
        self::maybeFoundInstitution($world, $tick, $date);
    }

    /**
     * Per-adult effort composed from three axes (design doc 07):
     *   want-to   — cohesion × sociability (extraversion) × conscientiousness (the diligence to
     *               actually show up and contribute),
     *   forced-to — the institution's mandate (× its effectiveness),
     *   paid-to   — effort the settlement hires with money,
     * each filling part of the gap left toward full participation.
     */
    public static function participationWeight(Agent $agent, float $cohesion, ?Institution $institution = null, float $paidTo = 0.0): float
    {
        $conscientiousness = (float) ($agent->trait('conscientiousness') ?? 50.0);
        $contribution = 0.75 + $conscientiousness / 200.0; // ~1.0 at the midpoint, ±25% across the range
        $wantTo = $cohesion * ((float) $agent->trait('sociability') / 100.0) * $contribution;
        $withForced = $institution?->liftedParticipation($wantTo) ?? $wantTo;

        return min(1.0, $withForced + $paidTo * (1.0 - $withForced));
    }

    private static function maybeOpenSandstormPrep(World $world, int $tick, TharadiDate $date): void
    {
        if ($world->activeProject !== null) {
            return;
        }
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // open at the new year (Naralis 1)
        }

        $population = count($world->livingAgents());
        $daysUntilSandstorm = self::SANDSTORM_START_MONTH * TharadiCalendar::DAYS_PER_MONTH;

        $world->activeProject = new Project(
            name: 'Sandstorm preparation',
            deadlineTick: $tick + $daysUntilSandstorm * TharadiCalendar::HOURS_PER_DAY,
            requiredEffort: $population * self::REQUIRED_PER_CAPITA,
            type: 'seasonal-preparation',
            initiator: 'the coming Sandstorm',
        );
    }

    private static function contributeDaily(World $world, int $tick): void
    {
        $project = $world->activeProject;
        if ($project === null || $project->resolved) {
            return;
        }

        $village = $world->village;
        $cohesion = $village->cohesion(count($world->livingAgents()));
        $institution = $village->institution;
        foreach ($world->livingAgents() as $agent) {
            if ($agent->ageInYears($tick) < self::ADULT_AGE) {
                continue;
            }

            // paid-to: the settlement hires extra effort from its treasury while it can afford to.
            $paidTo = 0.0;
            if ($village->stockpile->has('money', self::WAGE_FOR_PARTICIPATION)) {
                $village->stockpile->withdraw('money', self::WAGE_FOR_PARTICIPATION);
                $paidTo = self::PAID_TO_STRENGTH;
            }

            $project->contribute(self::participationWeight($agent, $cohesion, $institution, $paidTo));
        }
    }

    /**
     * Relief half of the loop: when storm-underpreparedness has persisted long enough
     * to count as a chronic cooperation deficit (not a one-off bad year), the settlement
     * founds the institution its culture calls for to compel the missing cooperation.
     */
    private static function maybeFoundInstitution(World $world, int $tick, TharadiDate $date): void
    {
        $village = $world->village;
        if ($village->institution !== null || $village->underpreparedYears < self::DEFICIT_YEARS_TO_INSTITUTION) {
            return;
        }

        $institution = Institution::emergeFor($village->culture, $tick);
        $village->institution = $institution;
        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — after %d storms caught it short, %s founds the %s to compel the preparation cohesion alone could not muster.',
            $date->dayOfMonth, $date->monthName, $date->year, $village->underpreparedYears, $village->name, $institution->name,
        ));
    }

    private static function resolveDue(World $world, int $tick, TharadiDate $date): void
    {
        $project = $world->activeProject;
        if ($project === null || $tick < $project->deadlineTick) {
            return;
        }

        $readiness = $project->readiness();
        $village = $world->village;
        $isFirst = $village->lastReadiness === null;
        $village->lastReadiness = $readiness;

        if ($readiness < 0.7) {
            $village->underpreparedYears++;
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — the Sandstorm catches %s underprepared (readiness %d%%); the dust takes its toll.',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name, (int) round($readiness * 100),
            ));
        } elseif ($isFirst) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s readies for its first Sandstorm together (readiness %d%%).',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name, (int) round($readiness * 100),
            ));
        }

        $world->activeProject = null;
    }
}
