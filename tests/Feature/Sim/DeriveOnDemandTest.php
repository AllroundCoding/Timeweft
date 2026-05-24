<?php

namespace Tests\Feature\Sim;

use App\Sim\Persistence\Timeline;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-38: derive-on-demand reconstruction (design docs 01/04) — recompute a tick's dense texture only
 * when looked at, by resuming the nearest checkpoint and replaying forward. The world it hands back is
 * byte-identical to one that ran straight there, down to what each agent was doing at that very tick.
 */
class DeriveOnDemandTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_reconstructing_a_tick_matches_a_straight_run_to_it(): void
    {
        $target = self::TICKS_PER_YEAR * 7;

        $straight = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $straight->advance($target);

        // A checkpoint anchored earlier; the texture at year 7 is derived by replaying forward.
        $source = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $source->advance(self::TICKS_PER_YEAR * 5);
        $timeline = new Timeline;
        $timeline->record($source);

        $reconstructed = $timeline->reconstructAt($target);

        $this->assertSame($target, $reconstructed->tick);
        $this->assertSame($this->chronicle($straight), $this->chronicle($reconstructed), 'the history matches');
        $this->assertSame($this->texture($straight), $this->texture($reconstructed), 'down to what each agent is doing at the tick');
    }

    public function test_it_replays_from_the_nearest_checkpoint_at_or_before_the_tick(): void
    {
        $timeline = new Timeline;
        foreach ([3, 6] as $year) {
            $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
            $world->advance(self::TICKS_PER_YEAR * $year);
            $timeline->record($world);
        }

        // A tick between the two checkpoints must replay from the year-3 one (the later one is past it).
        $betweenTick = self::TICKS_PER_YEAR * 4;
        $between = $timeline->reconstructAt($betweenTick);
        $this->assertSame($betweenTick, $between->tick, 'lands exactly on the requested tick, not the nearer-but-later checkpoint');

        $straight = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $straight->advance($betweenTick);
        $this->assertSame($this->texture($straight), $this->texture($between));
    }

    public function test_reconstruction_is_deterministic(): void
    {
        $source = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $source->advance(self::TICKS_PER_YEAR * 4);
        $timeline = new Timeline;
        $timeline->record($source);

        $tick = self::TICKS_PER_YEAR * 6;
        $this->assertSame(
            $this->texture($timeline->reconstructAt($tick)),
            $this->texture($timeline->reconstructAt($tick)),
            'the same query always yields the same texture',
        );
    }

    public function test_reconstructing_before_any_checkpoint_is_an_error(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->advance(self::TICKS_PER_YEAR * 5);
        $timeline = new Timeline;
        $timeline->record($world);

        $this->expectException(\RuntimeException::class);
        $timeline->reconstructAt(self::TICKS_PER_YEAR); // before the earliest checkpoint
    }

    /** @return list<string> */
    private function chronicle(World $world): array
    {
        return array_map(static fn ($e): string => $e->text, $world->chronicle->all());
    }

    /** The dense texture at the world's tick: who is doing what, with what hunger. @return list<string> */
    private function texture(World $world): array
    {
        return array_map(
            static fn (Agent $a): string => sprintf('%d|%s|%.1f', $a->id, $a->activity?->value ?? '-', $a->needs['hunger']->value),
            $world->village->livingAgents(),
        );
    }
}
