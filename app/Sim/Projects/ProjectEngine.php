<?php

namespace App\Sim\Projects;

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

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        self::maybeOpenSandstormPrep($world, $tick, $date);
        self::contributeDaily($world, $tick);
        self::resolveDue($world, $tick, $date);
    }

    /** want-to: cohesion (culture × social proximity) scaled by individual sociability. */
    public static function participationWeight(Agent $agent, float $cohesion): float
    {
        return $cohesion * ((float) $agent->trait('sociability') / 100.0);
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
        );
    }

    private static function contributeDaily(World $world, int $tick): void
    {
        $project = $world->activeProject;
        if ($project === null || $project->resolved) {
            return;
        }

        $cohesion = $world->village->cohesion(count($world->livingAgents()));
        foreach ($world->livingAgents() as $agent) {
            if ($agent->ageInYears($tick) < self::ADULT_AGE) {
                continue;
            }
            $project->contribute(self::participationWeight($agent, $cohesion));
        }
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
