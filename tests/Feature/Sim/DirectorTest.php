<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Direction\NullDirector;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-89: the story director is pluggable. The default rule-based director authors the world's
 * milestone beats; swapping in the null director leaves a purely emergent world — proof the engine
 * needs no narrative hand to run.
 */
class DirectorTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_the_default_director_authors_milestone_beats(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->advance(self::TICKS_PER_YEAR * 15);

        $this->assertNotEmpty($this->milestoneBeats($world), 'the rule director steers the canon trading-post beat');
    }

    public function test_the_null_director_leaves_a_purely_emergent_world(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->director = new NullDirector;
        $world->advance(self::TICKS_PER_YEAR * 15);

        $this->assertEmpty($this->milestoneBeats($world), 'no authored beats without a director');
        // The world keeps turning on pure emergence alone.
        $this->assertNotEmpty($world->livingAgents(), 'the village lives without a director');
        $births = array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'birth');
        $this->assertNotEmpty($births, 'children are still born');
    }

    public function test_the_director_is_deterministic(): void
    {
        $beats = static function (): array {
            $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
            $world->advance(self::TICKS_PER_YEAR * 15);

            return array_map(static fn (ChronicleEvent $e): string => $e->text, $world->chronicle->all());
        };

        $this->assertSame($beats(), $beats());
    }

    /** @return list<ChronicleEvent> */
    private function milestoneBeats(World $world): array
    {
        return array_values(array_filter(
            $world->chronicle->all(),
            static fn (ChronicleEvent $e): bool => str_starts_with($e->type, 'milestone'),
        ));
    }
}
