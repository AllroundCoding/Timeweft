<?php

namespace App\Sim\Economy;

use App\Sim\World\Agent;
use App\Sim\World\World;

/**
 * Professions & division of labor (design doc 16; TWT-98). Today every adult is undifferentiated labor;
 * this lets an agent **settle into a trade** — farming, building, water-bearing, tending — out of the
 * work it repeatedly takes in the labor market ({@see JobMarket}, TWT-97), nudged by the disposition its
 * traits give it. Specialization (doc 12) gains a bottom-up origin: nobody is assigned a role; it accretes.
 *
 * A trade is sticky. Once an agent has put in enough work it takes up the trade it has done most (with a
 * trait nudge to break early ties); after that the role holds against a stray off-day and only yields to
 * a *sustained* shift to other work — the hysteresis that makes roles stable rather than flickering. A
 * settled trade then feeds back: an agent favours its own work in the market (the path-dependence that
 * locks the role in) and is more productive at it than a hand pressed into unfamiliar labor.
 *
 * Deterministic and RNG-free — a pure read of job history and traits. It mutates only the agent's trade
 * (not goods, money, or the chronicle), so the canonical run stays byte-identical; the productivity it
 * defines is consumed as the agentic economy grows teeth (TWT-99 and beyond).
 */
final class ProfessionEngine
{
    /** Days of work an agent must have put in before any of it counts as a trade. */
    private const EXPERIENCE_TO_SETTLE = 20;

    /** How far trait disposition can stand in for experience when settling a trade — a nudge, not a verdict. */
    private const DISPOSITION_WEIGHT = 10.0;

    /** A rival trade must outscore the settled one by this factor to displace it — the stickiness of a role. */
    private const SWITCH_MARGIN = 1.5;

    /** How much more an agent produces at its own trade than at unfamiliar work. */
    private const ROLE_PRODUCTIVITY_BONUS = 0.5;

    public static function runDay(World $world): void
    {
        foreach ($world->village->livingAgents() as $agent) {
            self::settle($agent);
        }
    }

    /**
     * Settle (or re-settle) an agent's trade from its job history and disposition. A green hand has no
     * trade yet; a seasoned one takes up the work it has done most, and keeps it unless another kind of
     * work has clearly overtaken it.
     */
    public static function settle(Agent $agent): void
    {
        if (array_sum($agent->jobHistory) < self::EXPERIENCE_TO_SETTLE) {
            return; // too green to have a trade
        }

        $scores = [];
        foreach (JobMarket::jobTypes() as $type) {
            $scores[$type] = ($agent->jobHistory[$type] ?? 0) + JobMarket::affinity($agent, $type) * self::DISPOSITION_WEIGHT;
        }

        $dominant = self::strongest($scores);
        $current = $agent->profession;
        if ($current === null) {
            $agent->profession = $dominant;

            return;
        }
        if ($dominant !== $current && $scores[$dominant] >= ($scores[$current] ?? 0.0) * self::SWITCH_MARGIN) {
            $agent->profession = $dominant; // a sustained shift overtakes the old trade
        }
    }

    /** An agent's output multiplier at a kind of work: more at its own trade, baseline otherwise. */
    public static function productivity(?string $profession, string $jobType): float
    {
        return $profession === $jobType ? 1.0 + self::ROLE_PRODUCTIVITY_BONUS : 1.0;
    }

    /**
     * The highest-scoring trade, ties falling to the order the job types are defined — a determinism
     * invariant.
     *
     * @param  array<string,float>  $scores
     */
    private static function strongest(array $scores): string
    {
        $best = array_key_first($scores) ?? '';
        $bestScore = -INF;
        foreach ($scores as $type => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $type;
            }
        }

        return $best;
    }
}
