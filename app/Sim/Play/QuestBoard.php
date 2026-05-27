<?php

namespace App\Sim\Play;

use App\Sim\Economy\JobMarket;
use App\Sim\Economy\JobRequest;
use App\Sim\World\Agent;
use App\Sim\World\Village;
use App\Sim\World\World;

/**
 * The quest board (design doc 16; TWT-101): a settlement's open jobs surfaced to the player. Because the
 * player is just an agent, the board is the labor market's open-job list ({@see JobMarket::post()}) — the
 * same scarcity-priced demand every other agent answers — with the viewing agent's fit and the reward
 * attached, ranked so the best-paid work reads first. No separate quest system; quests are jobs with a UI.
 *
 * A pure, deterministic projection (the labor market is RNG-free): the same world yields the same board.
 * It reads the world, never mutates it — the canonical run is untouched. Accepting a quest means commanding
 * the player's agent to the work ({@see PlayerController}); fulfilling it settles the need through the
 * existing economy, exactly as any agent's labour does.
 */
final class QuestBoard
{
    /**
     * The settlement's open jobs as quests for one agent — the player's controlled agent, or any agent —
     * each carrying that agent's affinity and the scarcity-set reward, ranked by reward then type.
     *
     * @return list<Quest>
     */
    public static function forAgent(World $world, Village $village, Agent $agent): array
    {
        $quests = [];
        foreach (self::open($world, $village) as $job) {
            $quests[] = new Quest(
                type: $job->type,
                reward: $job->price,
                good: $job->good,
                shortfall: $job->shortfall,
                affinity: JobMarket::affinity($agent, $job->type),
                pull: $job->pull,
            );
        }

        // Best-paid work first; ties broken by type so the board reads the same on every run.
        usort($quests, static fn (Quest $a, Quest $b): int => [$b->reward, $a->type] <=> [$a->reward, $b->type]);

        return $quests;
    }

    /**
     * The raw open jobs a settlement has posted — the unfiltered board.
     *
     * @return list<JobRequest>
     */
    public static function open(World $world, Village $village): array
    {
        $population = count($village->livingAgents());

        return $population === 0 ? [] : JobMarket::post($world, $village, $population);
    }
}
