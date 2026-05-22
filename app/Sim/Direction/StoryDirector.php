<?php

namespace App\Sim\Direction;

use App\Sim\Projects\Project;
use App\Sim\Projects\ProjectEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiDate;
use App\Sim\World\World;

/**
 * Steers the world toward authored milestones on a time budget. Once a
 * milestone's prerequisites are met, its probability of resolving ramps up as
 * the deadline nears; if the organic path stalls, a bridging event forces the
 * beat. Emergence within guardrails — not a script.
 */
final class StoryDirector
{
    private const BASE_CHANCE_DAY = 0.0006;

    private const RAMP = 0.012;

    public static function evaluate(World $world, Milestone $milestone, int $tick, TharadiDate $date, Rng $rng): void
    {
        if ($milestone->achieved) {
            return;
        }

        // A communal project is already working toward this beat — let the village's effort decide it
        // (the same project mechanism in-world groups use); the director only steers what isn't pursued.
        if (self::pursuedByProject($world, $milestone)) {
            return;
        }

        // Dependency ordering: an authored beat waits — even past its deadline — for the beats it depends on.
        if (! self::prerequisitesMet($world, $milestone)) {
            return;
        }

        $population = count($world->livingAgents());
        $urgency = min(1.0, $date->year / $milestone->deadlineYear);

        // Organic path: only once the prerequisite is met, with a deadline-driven ramp.
        if ($population >= $milestone->prereqPopulation) {
            if ($rng->chance(self::BASE_CHANCE_DAY + $urgency * self::RAMP)) {
                self::achieve($world, $milestone, $tick, $date, forced: false, population: $population);

                return;
            }
        }

        // Deadline reached. A hard pin is force-bridged — it must hold, even against the world's grain
        // (a surfaced conflict). A soft beat the world didn't produce simply lapses: the sim wins, and
        // the unmet hope is recorded rather than silently buried (design doc 08).
        if ($date->year >= $milestone->deadlineYear) {
            if ($milestone->hard) {
                self::achieve($world, $milestone, $tick, $date, forced: true, population: $population);
            } elseif (! $milestone->lapsed) {
                $milestone->lapsed = true;
                $world->chronicle->record($tick, sprintf(
                    '%d %s, Year %d — the hoped-for %s never comes to pass; the world went another way.',
                    $date->dayOfMonth, $date->monthName, $date->year, $milestone->name,
                ), 'milestone-lapsed', [], [], ['soft-default']);
            }
        }
    }

    /**
     * The authored pins that had to be forced against the world's grain — conflicts between the
     * author's hand and emergence, surfaced for the author rather than silently picked (design doc 08).
     *
     * @return list<Milestone>
     */
    public static function conflicts(World $world): array
    {
        return array_values(array_filter(
            $world->milestones,
            static fn (Milestone $milestone): bool => $milestone->isConflict(),
        ));
    }

    /**
     * Spawn a communal project to pursue an authored beat — through the *same* path in-world groups
     * use ({@see ProjectEngine::open}). Top-down intent realized by bottom-up effort: if the village
     * musters enough by the deadline the beat is fulfilled organically; if not, the deadline backstop
     * (force or lapse) still holds. This is the "top-down and bottom-up are one mechanism" payoff.
     */
    public static function spawnProject(World $world, Milestone $milestone, int $deadlineTick, float $requiredPerCapita = 20.0): void
    {
        $population = count($world->livingAgents());
        ProjectEngine::open($world, new Project(
            name: $milestone->name,
            deadlineTick: $deadlineTick,
            requiredEffort: max(1.0, $population * $requiredPerCapita),
            type: 'authored-beat',
            initiator: "the village's ambition",
            milestoneName: $milestone->name,
        ));
    }

    /** Mark a beat fulfilled by the village's own communal effort — an organic, project-driven achievement. */
    public static function fulfillByProject(World $world, Milestone $milestone, int $tick, TharadiDate $date): void
    {
        $milestone->achieved = true;
        $milestone->achievedTick = $tick;
        $milestone->wasForced = false;

        $causes = [];
        foreach ($milestone->prerequisites as $name) {
            $prereqId = self::milestoneByName($world, $name)?->achievedEventId;
            if ($prereqId !== null) {
                $causes[] = $prereqId;
            }
        }
        $event = $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — through shared effort, %s completes the %s.',
            $date->dayOfMonth, $date->monthName, $date->year, $world->village->name, $milestone->name,
        ), 'milestone', [], $causes, ['project']);
        $milestone->achievedEventId = $event->id;
    }

    private static function pursuedByProject(World $world, Milestone $milestone): bool
    {
        foreach ($world->village->projects as $project) {
            if (! $project->resolved && $project->milestoneName === $milestone->name) {
                return true;
            }
        }

        return false;
    }

    private static function achieve(World $world, Milestone $milestone, int $tick, TharadiDate $date, bool $forced, int $population): void
    {
        $milestone->achieved = true;
        $milestone->achievedTick = $tick;
        $milestone->wasForced = $forced;

        $text = $forced
            ? sprintf(
                '%d %s, Year %d — with the deadline pressing, a caravan-master under Varis founds the %s regardless.',
                $date->dayOfMonth, $date->monthName, $date->year, $milestone->name,
            )
            : sprintf(
                '%d %s, Year %d — the village founds the %s (its people now number %d).',
                $date->dayOfMonth, $date->monthName, $date->year, $milestone->name, $population,
            );

        // The arc as a causal chain: this beat cites the beats it depended on (design doc 09).
        $causes = [];
        foreach ($milestone->prerequisites as $name) {
            $prereqId = self::milestoneByName($world, $name)?->achievedEventId;
            if ($prereqId !== null) {
                $causes[] = $prereqId;
            }
        }

        $event = $world->chronicle->record($tick, $text, 'milestone', [], $causes, [$forced ? 'deadline' : 'organic']);
        $milestone->achievedEventId = $event->id;
    }

    private static function prerequisitesMet(World $world, Milestone $milestone): bool
    {
        foreach ($milestone->prerequisites as $name) {
            $prereq = self::milestoneByName($world, $name);
            if ($prereq === null || ! $prereq->achieved) {
                return false;
            }
        }

        return true;
    }

    private static function milestoneByName(World $world, string $name): ?Milestone
    {
        foreach ($world->milestones as $candidate) {
            if ($candidate->name === $name) {
                return $candidate;
            }
        }

        return null;
    }
}
