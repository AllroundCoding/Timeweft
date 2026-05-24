<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-32: a checkpoint is a snapshot of boundary state + seed from which history replays deterministically
 * (design doc 01). Because every draw forks off the immutable seed (TWT-107), a world resumed from a
 * checkpoint and advanced is byte-identical to one that never stopped — the property derive-on-demand
 * (TWT-38) and counterfactual editing lean on.
 */
class CheckpointTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_replaying_from_a_checkpoint_matches_an_unbroken_run(): void
    {
        $straight = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $straight->advance(self::TICKS_PER_YEAR * 10);

        $checkpointed = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $checkpointed->advance(self::TICKS_PER_YEAR * 5);
        $resumed = $checkpointed->checkpoint()->resume();
        $resumed->advance(self::TICKS_PER_YEAR * 5);

        $this->assertSame(
            $this->chronicle($straight),
            $this->chronicle($resumed),
            'replay from the nearest checkpoint reproduces the unbroken history',
        );
        $this->assertSame($this->roster($straight), $this->roster($resumed), 'down to the same people');
        $this->assertSame($straight->tick, $resumed->tick);
    }

    public function test_a_checkpoint_is_an_independent_snapshot(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->advance(self::TICKS_PER_YEAR * 5);
        $checkpoint = $world->checkpoint();

        $world->advance(self::TICKS_PER_YEAR * 5); // the live world moves on, after capture

        $resumed = $checkpoint->resume();
        $this->assertSame($checkpoint->tick, $resumed->tick, 'the resumed world stands at the checkpoint tick, not the advanced one');
        $this->assertLessThan($world->tick, $resumed->tick, 'the snapshot was not disturbed by later advancing');
    }

    public function test_it_carries_the_tick_and_seed(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->advance(self::TICKS_PER_YEAR * 3);

        $checkpoint = $world->checkpoint();

        $this->assertSame($world->tick, $checkpoint->tick);
        $this->assertSame((new Rng('vaeris'))->seed(), $checkpoint->seed, 'the seed it replays from');
    }

    /** @return list<string> */
    private function chronicle(World $world): array
    {
        return array_map(static fn ($e): string => $e->text, $world->chronicle->all());
    }

    /** @return list<string> */
    private function roster(World $world): array
    {
        return array_map(
            static fn (Agent $a): string => sprintf('%d|%s|%s|%.1f', $a->id, $a->name, $a->sex, $a->trait('agility')),
            $world->village->livingAgents(),
        );
    }
}
