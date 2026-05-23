<?php

namespace App\Sim\World;

/**
 * A statistical population cohort (design doc 10; TWT-49) — a settlement (or tribe, or city) held as
 * an **age distribution** rather than a list of individual agents. The level-of-detail primitive that
 * lets the world scale from a village to a civilization: where attention falls, agents are tracked
 * individually; everywhere else a cohort carries the same demography as counts-by-age, advanced by
 * birth and death *rates* — cheap regardless of whether it stands for fifty souls or fifty thousand.
 *
 * Immutable: a year's advance ({@see CohortEngine}) returns a new cohort. Counts are fractional —
 * a cohort is the expected (mean-field) population the tracked simulation fluctuates around.
 */
readonly class Cohort
{
    /** @param  array<int,float>  $byAge  age in years => expected number of people that age */
    public function __construct(public array $byAge) {}

    /** A founding band of adults, spread evenly across an age range — the cohort form of fresh founders. */
    public static function ofAdults(float $count, int $minAge = 18, int $maxAge = 50): self
    {
        $span = $maxAge - $minAge + 1;
        $perYear = $count / $span;
        $byAge = [];
        for ($age = $minAge; $age <= $maxAge; $age++) {
            $byAge[$age] = $perYear;
        }

        return new self($byAge);
    }

    public function population(): float
    {
        return array_sum($this->byAge);
    }

    /** Expected number of people whose age falls in [min, max]. */
    public function inAgeRange(int $min, int $max): float
    {
        $total = 0.0;
        foreach ($this->byAge as $age => $count) {
            if ($age >= $min && $age <= $max) {
                $total += $count;
            }
        }

        return $total;
    }
}
