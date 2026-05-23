<?php

namespace App\Sim\Economy;

use App\Sim\Projects\ProjectEngine;
use App\Sim\World\Agent;
use App\Sim\World\Village;
use App\Sim\World\World;

/**
 * The settlement labor market (design doc 16; TWT-97) — the unifying mechanic that generalizes the one
 * hard-coded communal project (doc 07) into *needs as priced demand*. Each day a settlement's unmet
 * needs are posted as scarcity-priced {@see JobRequest}s (a starving village pays more, Pricing/TWT-47),
 * and every adult decides whether to take one by the **same participation calculus** that already drives
 * cooperation: want-to (disposition × cohesion) + faith + forced-to (institution) + paid-to (the wage).
 * "Anyone can pick up the woodworker job if they want" is exactly that weight crossing a threshold.
 *
 * Which job an agent gravitates to is biased by trait affinity, so different people drift to different
 * work — the bottom-up origin of the division of labor that settles into professions (TWT-98). The
 * allocation is recorded as each agent's job history; the wage and the shortfall a job names are what
 * agent-driven trade (TWT-99) will answer from afar.
 *
 * Needs in, allocated labor out, by utility — deterministic and RNG-free. This is the spine; in v1 it
 * records who would do the work without yet moving the goods or wages the older engines already settle,
 * so the canonical run stays byte-identical and the teeth grow as TWT-98/99 land.
 */
final class JobMarket
{
    private const ADULT_AGE = 16;

    /** Days of staple per head a settlement wants on hand; below it, the work is posted. */
    private const TARGET_STAPLE_DAYS = 10.0;

    /** Mean sickness (0..100) above which a settlement calls for carers. */
    private const TENDING_THRESHOLD = 5.0;

    /** The strongest the wage alone can pull labor (the paid-to ceiling) when a need is acute. */
    private const MAX_PULL = 0.4;

    /** Scarcity factor (price ÷ base) at which the wage's pull tops out — mirrors Pricing's MAX_FACTOR. */
    private const PULL_SATURATION = 4.0;

    /** A nominal day's wage for labor that supplies no tradable good (building, tending). */
    private const LABOR_WAGE = 2.0;

    /** Participation weight at which an agent takes up the work it is most drawn to. */
    private const TAKE_THRESHOLD = 0.3;

    /** How much an agent favours the work of its own profession (TWT-98) — the path-dependence that locks a role in. */
    private const ROLE_PREFERENCE = 1.5;

    /** Each job type's trait affinity, so different agents drift to different work (the seed of professions). */
    private const AFFINITY = [
        'farming' => ['conscientiousness', 'constitution'],
        'building' => ['constitution', 'dexterity'],
        'water-bearing' => ['heatTolerance', 'agility'],
        'tending' => ['generosity', 'senses'],
        // Trading is not posted as local work; it is the cross-settlement caravan action (TWT-99), but a
        // trader settles into the role here all the same — the curious and the sociable take to the road.
        'trading' => ['openness', 'sociability'],
    ];

    public static function runDay(World $world, int $tick): void
    {
        $village = $world->village;
        $population = count($village->livingAgents());
        if ($population === 0) {
            return;
        }

        $jobs = self::post($world, $village, $population);
        if ($jobs === []) {
            return;
        }

        self::allocate($village, $jobs, $tick);
    }

    /**
     * The day's open jobs: a settlement's unmet needs, each scarcity-priced.
     *
     * @return list<JobRequest>
     */
    public static function post(World $world, Village $village, int $population): array
    {
        $jobs = [];

        foreach (['food' => 'farming', 'water' => 'water-bearing'] as $good => $type) {
            $stock = $village->stockpile->amount($good);
            if ($stock / $population >= self::TARGET_STAPLE_DAYS) {
                continue; // the granary is comfortable; no call for hands
            }
            $definition = $world->goods->get($good);
            $base = $definition !== null ? $definition->value : 1.0;
            $price = Pricing::localPrice($base, $stock, $population);
            $jobs[] = new JobRequest(
                type: $type,
                price: $price,
                pull: self::pullFromScarcity($price / $base),
                good: $good,
                shortfall: max(0.0, self::TARGET_STAPLE_DAYS * $population - $stock),
            );
        }

        $urgency = self::projectUrgency($village);
        if ($urgency > 0.0) {
            $jobs[] = new JobRequest('building', self::LABOR_WAGE * (1.0 + $urgency), $urgency * self::MAX_PULL);
        }

        $sickness = self::meanSickness($village);
        if ($sickness > self::TENDING_THRESHOLD) {
            $share = $sickness / 100.0;
            $jobs[] = new JobRequest('tending', self::LABOR_WAGE * (1.0 + $share), $share * self::MAX_PULL);
        }

        return $jobs;
    }

    /**
     * Assign each adult to the job it is most drawn to (trait affinity × the wage's pull), taken up only
     * if the participation calculus — with that job's wage as the paid-to term — clears the threshold.
     *
     * @param  list<JobRequest>  $jobs
     */
    private static function allocate(Village $village, array $jobs, int $tick): void
    {
        $cohesion = $village->cohesion(count($village->livingAgents()));
        $institution = $village->institution;
        $faith = $village->faith();

        foreach ($village->livingAgents() as $agent) {
            if ($agent->ageInYears($tick) < self::ADULT_AGE) {
                continue; // children are not in the labor market
            }

            $chosen = self::bestFit($agent, $jobs);
            $willingness = ProjectEngine::participationWeight($agent, $cohesion, $institution, $chosen->pull, $faith);
            if ($willingness >= self::TAKE_THRESHOLD) {
                $agent->jobHistory[$chosen->type] = ($agent->jobHistory[$chosen->type] ?? 0) + 1;
            }
        }
    }

    /**
     * The job an agent is most drawn to: its trait affinity for the work, tilted toward the better-paid
     * (scarcer) jobs. Deterministic — ties fall to the order the jobs were posted.
     *
     * @param  list<JobRequest>  $jobs
     */
    private static function bestFit(Agent $agent, array $jobs): JobRequest
    {
        $best = $jobs[0];
        $bestScore = -1.0;
        foreach ($jobs as $job) {
            // Trait affinity, tilted toward the better-paid jobs, and again toward the agent's own trade.
            $role = $agent->profession === $job->type ? self::ROLE_PREFERENCE : 1.0;
            $score = self::affinity($agent, $job->type) * (0.5 + 0.5 * $job->pull) * $role;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $job;
            }
        }

        return $best;
    }

    /** The kinds of work this market knows — the job types a profession can settle into (TWT-98). */
    /** @return list<string> */
    public static function jobTypes(): array
    {
        return array_keys(self::AFFINITY);
    }

    /** An agent's natural fit for a kind of work, 0..1 — the mean of the two traits that work leans on. */
    public static function affinity(Agent $agent, string $type): float
    {
        $traits = self::AFFINITY[$type] ?? [];
        if ($traits === []) {
            return 0.5;
        }
        $total = 0.0;
        foreach ($traits as $trait) {
            $total += (float) ($agent->trait($trait) ?? 50.0);
        }

        return $total / (count($traits) * 100.0);
    }

    /** Map a good's scarcity factor (price ÷ base, ≥1 when short) to the wage's 0..1 pull on labor. */
    private static function pullFromScarcity(float $factor): float
    {
        $excess = max(0.0, $factor - 1.0) / (self::PULL_SATURATION - 1.0);

        return min(1.0, $excess) * self::MAX_PULL;
    }

    /** How far the most-pressing open communal project still falls short of done, 0..1. */
    private static function projectUrgency(Village $village): float
    {
        $urgency = 0.0;
        foreach ($village->projects as $project) {
            if ($project->resolved) {
                continue;
            }
            $urgency = max($urgency, 1.0 - $project->readiness());
        }

        return $urgency;
    }

    private static function meanSickness(Village $village): float
    {
        $living = $village->livingAgents();
        $total = 0.0;
        $counted = 0;
        foreach ($living as $agent) {
            $need = $agent->needs['sickness'] ?? null;
            if ($need !== null) {
                $total += $need->value;
                $counted++;
            }
        }

        return $counted > 0 ? $total / $counted : 0.0;
    }
}
