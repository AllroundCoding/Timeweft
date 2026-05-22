<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Direction\Milestone;
use App\Sim\Direction\StoryDirector;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-40: the author's hand. Soft beats (the default) yield to emergence and
 * lapse if the world doesn't produce them; hard pins must hold and are
 * force-bridged — and a forced pin is surfaced as a conflict with emergence,
 * not silently buried (design doc 08).
 */
class StoryDirectorPinTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_soft_beat_lapses_when_the_world_does_not_produce_it(): void
    {
        $world = $this->world();
        $soft = new Milestone('a soft hope', deadlineYear: 1, prereqPopulation: 999); // unreachable organically
        $world->milestones = [$soft];

        $this->evaluatePastDeadline($world, $soft);

        $this->assertFalse($soft->achieved, 'a soft beat is not forced');
        $this->assertTrue($soft->lapsed, 'a soft beat lapses — the sim wins');
        $this->assertFalse($soft->isConflict());
        $this->assertNotEmpty(array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'milestone-lapsed'));
    }

    public function test_a_hard_pin_is_forced_and_surfaced_as_a_conflict(): void
    {
        $world = $this->world();
        $hard = new Milestone('a pinned beat of canon', deadlineYear: 1, prereqPopulation: 999, hard: true);
        $world->milestones = [$hard];

        $this->evaluatePastDeadline($world, $hard);

        $this->assertTrue($hard->achieved, 'a hard pin must hold');
        $this->assertTrue($hard->wasForced, 'it had to be force-bridged against emergence');
        $this->assertTrue($hard->isConflict());
        $this->assertSame([$hard], StoryDirector::conflicts($world), 'the conflict is surfaced for the author');
    }

    public function test_before_its_deadline_a_soft_beat_neither_fires_nor_lapses(): void
    {
        $world = $this->world();
        $soft = new Milestone('a future hope', deadlineYear: 50, prereqPopulation: 999);
        $world->milestones = [$soft];

        $tick = self::TICKS_PER_YEAR * 3; // year ~4, far inside the budget
        StoryDirector::evaluate($world, $soft, $tick, TharadiCalendar::fromTick($tick), $world->rng);

        $this->assertFalse($soft->achieved);
        $this->assertFalse($soft->lapsed);
    }

    private function evaluatePastDeadline(World $world, Milestone $milestone): void
    {
        $tick = self::TICKS_PER_YEAR * 3; // year ~4, past a deadline of 1
        StoryDirector::evaluate($world, $milestone, $tick, TharadiCalendar::fromTick($tick), $world->rng);
    }

    private function world(): World
    {
        $world = new World(new Rng('pins'));
        $world->village = new Village('Testhold', 'Tharados', [
            new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', -20 * self::TICKS_PER_YEAR, ['agility' => 50.0], []),
        ]);

        return $world;
    }
}
