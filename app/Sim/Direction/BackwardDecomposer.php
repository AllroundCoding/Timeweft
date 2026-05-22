<?php

namespace App\Sim\Direction;

/**
 * Generation run *backwards* (design doc 08): from an authored end-state, decompose into the
 * preconditions that must precede it — an empire needs a kingdom, which needs a town, which needs a
 * trading post, which needs a settlement — each pinned a lead-time earlier than the beat it enables.
 *
 * The result is a topologically-ordered constraint graph of {@see Milestone}s (hard pins, wired with
 * prerequisites and back-dated deadlines) that the forward {@see StoryDirector} then realizes in
 * order. The generative inverse of forward simulation: instead of "what does this seed grow into?",
 * it answers "what past justifies this future?".
 */
final class BackwardDecomposer
{
    /** kind => list of [prerequisite kind, lead years it must precede this one by]. A leaf has none. */
    private const PRECONDITIONS = [
        'empire' => [['kingdom', 80]],
        'kingdom' => [['town', 50]],
        'town' => [['trading post', 25]],
        'trading post' => [['settlement', 10]],
        'settlement' => [],
    ];

    /** The population a kind implies — the demographic floor a waypoint needs to be plausible. */
    private const POPULATION = [
        'settlement' => 5,
        'trading post' => 12,
        'town' => 40,
        'kingdom' => 150,
        'empire' => 400,
    ];

    /**
     * Decompose an end-state into the ordered constraint graph that justifies it.
     *
     * @return list<Milestone> earliest precondition first, the end-state last
     */
    public static function decompose(Waypoint $target): array
    {
        $deadlines = self::deadlines($target);

        // Topological order falls out of the back-dated deadlines: a precondition is always earlier.
        uasort($deadlines, static fn (int $a, int $b): int => $a <=> $b);

        $milestones = [];
        foreach ($deadlines as $kind => $byYear) {
            $milestones[] = new Milestone(
                name: $kind,
                deadlineYear: max(1, $byYear),
                prereqPopulation: self::POPULATION[$kind] ?? 0,
                prerequisites: array_map(static fn (array $p): string => $p[0], self::PRECONDITIONS[$kind] ?? []),
                hard: true, // authored facts: pinned, force-bridged if emergence won't reach them
            );
        }

        return $milestones;
    }

    /**
     * Whether the end-state is reachable in the time given — no precondition is pushed before the
     * world begins (Year 1). Tight constraints make a fact unsatisfiable (the lore checker, TWT-42,
     * builds on this).
     */
    public static function isSatisfiable(Waypoint $target): bool
    {
        foreach (self::deadlines($target) as $byYear) {
            if ($byYear < 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * The back-dated deadline of every waypoint in the target's cone — the tightest (earliest) when a
     * kind is required by more than one dependent (shortest-path relaxation over the precondition graph).
     *
     * @return array<string,int> kind => the year by which it must hold
     */
    private static function deadlines(Waypoint $target): array
    {
        $deadlines = [];
        $queue = [[$target->kind, $target->byYear]];
        while ($queue !== []) {
            [$kind, $byYear] = array_shift($queue);
            if (isset($deadlines[$kind]) && $deadlines[$kind] <= $byYear) {
                continue; // already pinned at least this early
            }
            $deadlines[$kind] = $byYear;
            foreach (self::PRECONDITIONS[$kind] ?? [] as [$prereqKind, $lead]) {
                $queue[] = [$prereqKind, $byYear - $lead];
            }
        }

        return $deadlines;
    }
}
