<?php

namespace Tests\Feature\Sim;

use App\Sim\Play\Quest;
use App\Sim\Play\QuestBoard;
use App\Sim\Support\Rng;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-101 — the quest board is a settlement's open job list surfaced to an agent: scarcity-set rewards,
 * the agent's fit, ranked best-paid first. A pure projection of the labor market (RNG-free), so the same
 * world yields the same board; it reads the world and never mutates it, so the canonical run is untouched.
 * Accepting/fulfilling (command the player's agent to the work) is the TWT-100 integration.
 */
class QuestBoardTest extends TestCase
{
    public function test_an_empty_granary_posts_paid_work(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $village = $world->village;
        $village->stockpile->withdraw('food', $village->stockpile->amount('food')); // starve it → farming work posts

        $quests = QuestBoard::forAgent($world, $village, $village->agents[0]);

        $this->assertNotEmpty($quests, 'a starving settlement posts open work');
        $farming = null;
        foreach ($quests as $quest) {
            if ($quest->type === 'farming') {
                $farming = $quest;
            }
        }
        $this->assertNotNull($farming, 'the food shortfall is a farming quest');
        $this->assertGreaterThan(0.0, $farming->reward, 'scarcity sets a reward');
        $this->assertGreaterThan(0.0, $farming->shortfall);
        $this->assertGreaterThanOrEqual(0.0, $farming->affinity);
        $this->assertLessThanOrEqual(1.0, $farming->affinity);
    }

    public function test_the_board_ranks_best_paid_first(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $village = $world->village;
        $village->stockpile->withdraw('food', $village->stockpile->amount('food'));
        $village->stockpile->withdraw('water', $village->stockpile->amount('water'));

        $quests = QuestBoard::forAgent($world, $village, $village->agents[0]);

        $this->assertGreaterThanOrEqual(2, count($quests), 'an empty granary posts both food and water work');
        for ($i = 1; $i < count($quests); $i++) {
            $this->assertGreaterThanOrEqual($quests[$i]->reward, $quests[$i - 1]->reward, 'ranked by reward, best paid first');
        }
    }

    public function test_a_comfortable_settlement_offers_nothing(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $village = $world->village;
        // Pile the granary high so no staple is short, and nothing else is pressing on day zero.
        $village->stockpile->add('food', 10_000.0);
        $village->stockpile->add('water', 10_000.0);

        $this->assertSame([], QuestBoard::open($world, $village), 'a well-stocked, healthy settlement posts no work');
    }

    public function test_the_board_is_deterministic(): void
    {
        $build = function (): array {
            $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
            $village = $world->village;
            $village->stockpile->withdraw('food', $village->stockpile->amount('food'));

            return array_map(
                static fn (Quest $q): string => $q->type.':'.round($q->reward, 3).':'.round($q->affinity, 3),
                QuestBoard::forAgent($world, $village, $village->agents[0]),
            );
        };

        $this->assertSame($build(), $build(), 'same world → same board');
    }
}
