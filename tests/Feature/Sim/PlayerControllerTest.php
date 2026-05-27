<?php

namespace Tests\Feature\Sim;

use App\Sim\Behavior\Activity;
use App\Sim\Play\PlayerController;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-100 — the controlled-agent driver. One tracked agent becomes the player: its activity comes from
 * input, overriding autonomy, while everyone else acts on their own stack. Opt-in, so a headless run
 * embodies no one and stays byte-identical (the canonical-hash gate and SimulationDeterminismTest pin
 * that); these pin the override, isolation, and reproducibility.
 */
class PlayerControllerTest extends TestCase
{
    private const YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_the_controller_addresses_only_its_agent(): void
    {
        $controller = new PlayerController(7, Activity::Working);

        $this->assertTrue($controller->controls(7));
        $this->assertFalse($controller->controls(8));
        $this->assertSame(Activity::Working, $controller->activityFor(7));
        $this->assertNull($controller->activityFor(8), 'other agents are never addressed');

        $controller->release();
        $this->assertNull($controller->activityFor(7), 'released → autonomy resumes');
    }

    public function test_the_player_overrides_autonomy_for_its_agent(): void
    {
        // What the first agent does on its own at tick 1.
        $autonomous = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $autonomous->advance(1);
        $autoActivity = $autonomous->village->agents[0]->activity;

        // Embody that agent and command a *different* activity — it must obey, not follow autonomy.
        $commanded = $autoActivity === Activity::Working ? Activity::Sleeping : Activity::Working;
        $played = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $played->playerController = new PlayerController($played->village->agents[0]->id, $commanded);
        $played->advance(1);

        $this->assertSame($commanded, $played->village->agents[0]->activity, 'the player drives its agent');
    }

    public function test_the_rest_of_the_world_is_unaffected(): void
    {
        $autonomous = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $autonomous->advance(1);

        $played = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $played->playerController = new PlayerController($played->village->agents[0]->id, Activity::Sheltering);
        $played->advance(1);

        // A different agent acts exactly as it would have without a player in the world.
        $this->assertSame(
            $autonomous->village->agents[1]->activity,
            $played->village->agents[1]->activity,
            'the player is one agent among many',
        );
    }

    public function test_play_is_reproducible(): void
    {
        $run = function (): array {
            $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
            $world->playerController = new PlayerController($world->village->agents[0]->id, Activity::Working);
            $world->advance(self::YEAR);

            return array_map(
                static fn ($a): string => $a->id.':'.($a->activity?->value ?? '-').':'.($a->alive ? 'alive' : 'dead'),
                $world->village->agents,
            );
        };

        $this->assertSame($run(), $run(), 'same seed + same commands → the same world');
    }
}
