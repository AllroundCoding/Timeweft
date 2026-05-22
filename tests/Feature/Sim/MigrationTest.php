<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\MigrationEngine;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-48: people flow between settlements — a crowded or famine-struck one sheds
 * its unattached adults to a settlement with room, making boom-bust a regional
 * flow rather than a per-village die-back.
 */
class MigrationTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_crowding_pushes_and_headroom_pulls(): void
    {
        $crowded = new Village('Crowdholm', 'Tharados', landYield: 4.0); // tiny ceiling
        $crowded->carryingCapacity = 4;
        $crowded->agents = $this->adults(20);

        $spacious = new Village('Roomhold', 'Tharados', landYield: 40.0);
        $spacious->carryingCapacity = 40;
        $spacious->agents = $this->adults(3);

        $this->assertGreaterThan(0.0, MigrationEngine::pushPressure($crowded), 'overcrowding pushes people out');
        $this->assertSame(0.0, MigrationEngine::pushPressure($spacious), 'a settlement with room pushes no one');
        $this->assertGreaterThan(MigrationEngine::desirability($crowded), MigrationEngine::desirability($spacious), 'room draws migrants');
    }

    public function test_singles_flee_a_crowded_settlement_for_one_with_room(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 24); // overcrowds its oasis
        $roomhold = $world->foundVillage('Roomhold', 3, landYield: 60.0); // a spacious frontier
        $crowded = $world->villages[0];

        $world->advance(self::TICKS_PER_YEAR * 10);

        $migrations = array_values(array_filter(
            $world->chronicle->all(),
            static fn (ChronicleEvent $e): bool => $e->type === 'migration',
        ));
        $this->assertNotEmpty($migrations, 'crowding drives at least one migration');

        // A migrant named in an event now lives in the destination, not the settlement it left.
        $migrantId = $migrations[0]->subjects[0];
        $inRoomhold = array_filter($roomhold->agents, static fn ($a): bool => $a->id === $migrantId);
        $inCrowded = array_filter($crowded->agents, static fn ($a): bool => $a->id === $migrantId);
        $this->assertNotEmpty($inRoomhold, 'the migrant joined the destination');
        $this->assertEmpty($inCrowded, 'and left the settlement it fled');
    }

    public function test_a_single_settlement_run_never_migrates(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->advance(self::TICKS_PER_YEAR * 22);

        $migrations = array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'migration');
        $this->assertEmpty($migrations, 'with nowhere to go, no one migrates (and the run stays byte-identical)');
    }

    /** @return list<Agent> */
    private function adults(int $count): array
    {
        return array_map(
            fn (int $i): Agent => new Agent(
                $i, "A{$i}", 'Vulpini', 'Tharados', $i % 2 === 0 ? 'f' : 'm', -25 * self::TICKS_PER_YEAR, ['agility' => 50.0], [],
            ),
            range(1, $count),
        );
    }
}
