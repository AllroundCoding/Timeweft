<?php

namespace App\Sim\Direction;

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

        // Fallback: the deadline forces the beat regardless of the organic path.
        if ($date->year >= $milestone->deadlineYear) {
            self::achieve($world, $milestone, $tick, $date, forced: true, population: $population);
        }
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
