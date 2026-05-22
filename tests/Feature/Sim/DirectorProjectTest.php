<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Direction\Milestone;
use App\Sim\Direction\StoryDirector;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-44: the story director spawns a communal project through the *same* path
 * in-world groups use, so an authored beat is realized by the village's own
 * effort — top-down intent met by bottom-up cooperation, one mechanism.
 */
class DirectorProjectTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_the_director_spawns_a_project_the_village_completes(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $beat = new Milestone('a communal well', deadlineYear: 99, prereqPopulation: 0);
        $world->milestones = [$beat];

        // The director pursues the beat by spawning a project, due in a year, of modest effort.
        StoryDirector::spawnProject($world, $beat, deadlineTick: self::TICKS_PER_YEAR, requiredPerCapita: 3.0);
        $this->assertTrue($world->hasOpenProject(), 'the project is opened through the shared mechanism');

        $world->advance((int) (self::TICKS_PER_YEAR * 1.5));

        $this->assertTrue($beat->achieved, 'the village completes the beat');
        $this->assertFalse($beat->wasForced, 'fulfilled organically through effort, not force-bridged');

        $fulfilled = array_filter(
            $world->chronicle->all(),
            static fn (ChronicleEvent $e): bool => $e->type === 'milestone' && str_contains($e->text, 'through shared effort'),
        );
        $this->assertNotEmpty($fulfilled, 'its fulfilment is chronicled as the people\'s own work');
    }

    public function test_an_unfunded_beat_falls_back_to_the_directors_deadline(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        // A hard pin pursued by a project that demands far more effort than the village can muster.
        $beat = new Milestone('a great pyramid', deadlineYear: 2, prereqPopulation: 0, hard: true);
        $world->milestones = [$beat];
        StoryDirector::spawnProject($world, $beat, deadlineTick: self::TICKS_PER_YEAR, requiredPerCapita: 100000.0);

        $world->advance(self::TICKS_PER_YEAR * 3);

        // The project failed, but the hard pin still holds — forced by the deadline backstop.
        $this->assertTrue($beat->achieved);
        $this->assertTrue($beat->wasForced, 'the unmet hard pin is force-bridged once the project lapses');
    }
}
