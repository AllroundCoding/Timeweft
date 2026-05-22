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
 * TWT-39: the director steers an authored *arc* of several milestones with
 * dependency ordering — a beat waits for the beats it depends on — and a fired
 * beat cites its prerequisites, so the arc is a causal chain in the chronicle.
 */
class StoryDirectorDependencyTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_beat_waits_for_its_prerequisite_even_past_its_deadline(): void
    {
        [$world, , $b] = $this->arc();
        $date = TharadiCalendar::fromTick(self::TICKS_PER_YEAR * 3); // well past the deadline

        for ($i = 0; $i < 5; $i++) {
            StoryDirector::evaluate($world, $b, self::TICKS_PER_YEAR * 3, $date, $world->rng);
        }

        $this->assertFalse($b->achieved, 'the dependent beat cannot fire while its prerequisite is unmet');
        $this->assertNull($b->achievedEventId);
    }

    public function test_once_the_prerequisite_fires_the_dependent_beat_can_too_and_cites_it(): void
    {
        [$world, $a, $b] = $this->arc();
        $tick = self::TICKS_PER_YEAR * 3;
        $date = TharadiCalendar::fromTick($tick);

        StoryDirector::evaluate($world, $a, $tick, $date, $world->rng);
        $this->assertTrue($a->achieved, 'the prerequisite fires (forced at its deadline)');

        StoryDirector::evaluate($world, $b, $tick, $date, $world->rng);
        $this->assertTrue($b->achieved, 'with its prerequisite met, the dependent beat fires');

        $milestoneEvents = array_values(array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'milestone'));
        $bEvent = $milestoneEvents[array_key_last($milestoneEvents)];
        $this->assertNotNull($a->achievedEventId);
        $this->assertContains($a->achievedEventId, $bEvent->causes, 'the dependent beat cites its prerequisite');
    }

    public function test_the_seeded_arc_unfolds_in_order(): void
    {
        // Author a dependent second beat onto a seeded world and run it through the real loop.
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->milestones[] = new Milestone(
            name: 'caravan guild on the trade road',
            deadlineYear: 25,
            prereqPopulation: 14,
            prerequisites: ['trading post on the caravan road'],
        );
        $world->advance(self::TICKS_PER_YEAR * 30);

        $post = $this->milestoneEvent($world, 'trading post on the caravan road');
        $guild = $this->milestoneEvent($world, 'caravan guild on the trade road');
        $this->assertNotNull($post, 'the trading post is founded');
        $this->assertNotNull($guild, 'the dependent guild is founded');

        $this->assertLessThan($guild->tick, $post->tick, 'the post comes before the guild it grows from');
        $this->assertContains($post->id, $guild->causes, 'the guild cites the post as its cause');
    }

    /** @return array{0:World,1:Milestone,2:Milestone} */
    private function arc(): array
    {
        $world = new World(new Rng('arc'));
        $world->village = new Village('Testhold', 'Tharados', [
            new Agent(1, 'A', 'Vulpini', 'Tharados', 'f', -20 * self::TICKS_PER_YEAR, ['agility' => 50.0], []),
            new Agent(2, 'B', 'Vulpini', 'Tharados', 'm', -22 * self::TICKS_PER_YEAR, ['agility' => 50.0], []),
        ]);
        $a = new Milestone('A', deadlineYear: 1, prereqPopulation: 0);
        $b = new Milestone('B', deadlineYear: 1, prereqPopulation: 0, prerequisites: ['A']);
        $world->milestones = [$a, $b];

        return [$world, $a, $b];
    }

    private function milestoneEvent(World $world, string $nameFragment): ?ChronicleEvent
    {
        foreach ($world->chronicle->all() as $event) {
            if ($event->type === 'milestone' && str_contains($event->text, $nameFragment)) {
                return $event;
            }
        }

        return null;
    }
}
