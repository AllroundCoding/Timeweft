<?php

namespace App\Sim\Direction;

/**
 * Validates a set of authored facts (end-state {@see Waypoint}s) against the constraint graph
 * {@see BackwardDecomposer} derives, and flags the ones that can't all be true — so an author
 * learns early that their pins contradict, instead of getting a silently-broken world (design doc 08).
 *
 * Two kinds of contradiction:
 *  - **unsatisfiable in time** — a fact's decomposition pushes a precondition before the world begins;
 *  - **contradictory pins** — one fact needs another, authored fact's kind *earlier* than it is pinned.
 */
final class LoreCheck
{
    /**
     * @return list<string> one human-readable problem per contradiction; empty means consistent
     */
    public static function check(Waypoint ...$facts): array
    {
        $authored = [];
        foreach ($facts as $fact) {
            $authored[$fact->kind] = $fact->byYear;
        }

        $problems = [];
        foreach ($facts as $fact) {
            foreach (BackwardDecomposer::requiredDeadlines($fact) as $kind => $requiredBy) {
                if ($requiredBy < 1) {
                    $problems[] = sprintf(
                        "'%s by Year %d' is unsatisfiable: its %s would have to exist by Year %d, before the world begins.",
                        $fact->kind, $fact->byYear, $kind, $requiredBy,
                    );
                }
                if ($kind !== $fact->kind && isset($authored[$kind]) && $authored[$kind] > $requiredBy) {
                    $problems[] = sprintf(
                        "'%s by Year %d' needs a %s by Year %d, but a %s is authored only by Year %d.",
                        $fact->kind, $fact->byYear, $kind, $requiredBy, $kind, $authored[$kind],
                    );
                }
            }
        }

        return array_values(array_unique($problems));
    }

    public static function isConsistent(Waypoint ...$facts): bool
    {
        return self::check(...$facts) === [];
    }
}
