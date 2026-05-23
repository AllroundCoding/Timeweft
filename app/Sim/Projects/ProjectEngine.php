<?php

namespace App\Sim\Projects;

use App\Sim\Culture\Faith;
use App\Sim\Direction\StoryDirector;
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

    private const SUCCESS_THRESHOLD = 0.7; // effort fraction at which a project counts as succeeding

    private const SANDSTORM_START_MONTH = 2; // Kalimos = first Sandstorm month

    private const DEFICIT_YEARS_TO_INSTITUTION = 3; // a deficit this persistent stops being a fluke

    private const WAGE_FOR_PARTICIPATION = 2.0; // treasury money to hire one adult for a day

    private const PAID_TO_STRENGTH = 0.12;      // hired effort is a modest supplement, not a deficit-eraser

    private const FAITH_STRENGTH = 0.12;        // how far devout faith lifts cooperation (eases, never erases, the deficit)

    private const SICKNESS_LABOR_PENALTY = 0.75; // how far full sickness saps the capacity to work (TWT-115)

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        self::maybeOpenSandstormPrep($world, $tick, $date);
        self::contributeDaily($world, $tick);
        self::resolveDue($world, $tick, $date);
        self::maybeFoundInstitution($world, $tick, $date);
    }

    /**
     * Per-adult effort, each axis filling part of the gap left toward full participation (doc 07):
     *   want-to   — cohesion × sociability (extraversion) × conscientiousness (the diligence to show up),
     *   faith     — the devout pitch in for the in-group unbidden (Big Gods, doc 11), per-agent adherence,
     *   forced-to — the institution's mandate (× its effectiveness),
     *   paid-to   — effort the settlement hires with money.
     * The whole is then scaled by health: illness saps the *capacity* to work (TWT-115), so a sick
     * agent contributes proportionally less however willing, paid, or compelled it is.
     */
    public static function participationWeight(Agent $agent, float $cohesion, ?Institution $institution = null, float $paidTo = 0.0, ?Faith $faith = null): float
    {
        $conscientiousness = (float) ($agent->trait('conscientiousness') ?? 50.0);
        $contribution = 0.75 + $conscientiousness / 200.0; // ~1.0 at the midpoint, ±25% across the range
        $wantTo = $cohesion * ((float) $agent->trait('sociability') / 100.0) * $contribution;

        $faithPull = $faith !== null ? $faith->cooperativePull() * $faith->adherenceOf($agent) * self::FAITH_STRENGTH : 0.0;
        $withFaith = $wantTo + $faithPull * (1.0 - $wantTo);

        $withForced = $institution?->liftedParticipation($withFaith) ?? $withFaith;
        $effort = min(1.0, $withForced + $paidTo * (1.0 - $withForced));

        // A plague- or famine-struck workforce prepares less, deepening the cooperation deficit that
        // institutions rise to fill — the link from health through cooperation to rise-and-fall.
        $sickness = isset($agent->needs['sickness']) ? $agent->needs['sickness']->value : 0.0;

        return $effort * (1.0 - $sickness / 100.0 * self::SICKNESS_LABOR_PENALTY);
    }

    /**
     * The one path every communal endeavor opens through — Sandstorm prep, and the beats the story
     * director spawns alike (design docs 07 + 08). Top-down and bottom-up steering, one mechanism.
     */
    public static function open(World $world, Project $project): void
    {
        $world->village->projects[] = $project;
    }

    private static function maybeOpenSandstormPrep(World $world, int $tick, TharadiDate $date): void
    {
        if (self::openOfType($world, 'seasonal-preparation') !== null) {
            return;
        }
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // open at the new year (Naralis 1)
        }

        $population = count($world->livingAgents());
        $daysUntilSandstorm = self::SANDSTORM_START_MONTH * TharadiCalendar::DAYS_PER_MONTH;

        self::open($world, new Project(
            name: 'Sandstorm preparation',
            deadlineTick: $tick + $daysUntilSandstorm * TharadiCalendar::HOURS_PER_DAY,
            requiredEffort: $population * self::REQUIRED_PER_CAPITA,
            type: 'seasonal-preparation',
            initiator: 'the coming Sandstorm',
        ));
    }

    private static function contributeDaily(World $world, int $tick): void
    {
        $open = array_filter($world->village->projects, static fn (Project $p): bool => ! $p->resolved);
        if ($open === []) {
            return;
        }

        $village = $world->village;
        $cohesion = $village->cohesion(count($world->livingAgents()));
        $institution = $village->institution;
        $faith = $village->faith();
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

            $effort = self::participationWeight($agent, $cohesion, $institution, $paidTo, $faith);
            foreach ($open as $project) {
                $project->contribute($effort);
            }
        }
    }

    /** @return list<Project> */
    private static function openProjects(World $world): array
    {
        return array_values(array_filter($world->village->projects, static fn (Project $p): bool => ! $p->resolved));
    }

    private static function openOfType(World $world, string $type): ?Project
    {
        foreach (self::openProjects($world) as $project) {
            if ($project->type === $type) {
                return $project;
            }
        }

        return null;
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
        $event = $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — after %d storms caught it short, %s founds the %s to compel the preparation cohesion alone could not muster.',
            $date->dayOfMonth, $date->monthName, $date->year, $village->underpreparedYears, $village->name, $institution->name,
        ), 'institution-founded', [], $village->underpreparedEventIds, ['cooperation-deficit']);
        $village->institutionEventId = $event->id;
    }

    private static function resolveDue(World $world, int $tick, TharadiDate $date): void
    {
        foreach (self::openProjects($world) as $project) {
            if ($tick < $project->deadlineTick) {
                continue;
            }
            $project->resolved = true;

            if ($project->milestoneName !== null) {
                self::resolveAuthoredBeat($world, $project, $tick, $date);
            } else {
                self::resolveSandstorm($world, $project, $tick, $date);
            }
        }
    }

    private static function resolveSandstorm(World $world, Project $project, int $tick, TharadiDate $date): void
    {
        $readiness = $project->readiness();
        $village = $world->village;
        $isFirst = $village->lastReadiness === null;
        $village->lastReadiness = $readiness;

        if ($readiness < 0.7) {
            $village->underpreparedYears++;
            $event = $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — the Sandstorm catches %s underprepared (readiness %d%%); the dust takes its toll.',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name, (int) round($readiness * 100),
            ), 'sandstorm-underprepared', [], [], ['cooperation-deficit']);
            $village->underpreparedEventIds[] = $event->id;
        } elseif ($isFirst) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — %s readies for its first Sandstorm together (readiness %d%%).',
                $date->dayOfMonth, $date->monthName, $date->year, $village->name, (int) round($readiness * 100),
            ), 'sandstorm-prepared');
        }
    }

    /**
     * A director-spawned project realizing an authored beat: if the village mustered enough effort,
     * the beat is fulfilled organically — through the people's own work, not a forced bridge. If it
     * fell short, the milestone is left for the director's deadline backstop (force or lapse).
     */
    private static function resolveAuthoredBeat(World $world, Project $project, int $tick, TharadiDate $date): void
    {
        if ($project->readiness() < self::SUCCESS_THRESHOLD) {
            return;
        }
        foreach ($world->milestones as $milestone) {
            if ($milestone->name === $project->milestoneName && ! $milestone->achieved) {
                StoryDirector::fulfillByProject($world, $milestone, $tick, $date);
            }
        }
    }
}
