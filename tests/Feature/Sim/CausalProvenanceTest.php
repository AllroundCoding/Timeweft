<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\Chronicle;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-27: every emitted event is a node carrying its causal provenance, so the
 * chronicle is the timeline's causal graph — the substrate the M4 ripple needs.
 */
class CausalProvenanceTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_chronicle_entry_is_a_node_with_typed_provenance(): void
    {
        $chronicle = new Chronicle;
        $cause = $chronicle->record(10, 'a union forms', 'pairing', [1, 2]);
        $effect = $chronicle->record(20, 'a child is born', 'birth', [3, 1, 2], [$cause->id]);

        $this->assertSame(1, $cause->id);
        $this->assertSame(2, $effect->id);
        $this->assertSame('birth', $effect->type);
        $this->assertSame([$cause->id], $effect->causes);
        $this->assertSame($effect, $chronicle->last());
    }

    public function test_a_seeded_world_forms_a_connected_causal_graph(): void
    {
        $events = $this->chronicleOf('vaeris', 8, 30);
        $byId = [];
        foreach ($events as $e) {
            $byId[$e->id] = $e;
        }

        // Every cited cause is a real, earlier event — no dangling edges.
        foreach ($events as $e) {
            foreach ($e->causes as $causeId) {
                $this->assertArrayHasKey($causeId, $byId, "event {$e->id} cites missing cause {$causeId}");
                $this->assertLessThan($e->id, $causeId, 'a cause must precede its effect');
            }
        }

        // Births trace back to the union that produced them.
        $births = array_filter($events, static fn (ChronicleEvent $e): bool => $e->type === 'birth');
        $this->assertNotEmpty($births);
        $birthCitesPairing = false;
        foreach ($births as $birth) {
            foreach ($birth->causes as $causeId) {
                if (($byId[$causeId] ?? null)?->type === 'pairing') {
                    $birthCitesPairing = true;
                }
            }
        }
        $this->assertTrue($birthCitesPairing, 'at least one birth cites its parents\' pairing');

        // Deaths always record *why* — never an unexplained end.
        $deaths = array_filter($events, static fn (ChronicleEvent $e): bool => $e->type === 'death');
        $this->assertNotEmpty($deaths);
        foreach ($deaths as $death) {
            $this->assertNotEmpty($death->factors, "death {$death->id} records no cause");
            $this->assertNotEmpty($death->subjects, 'a death names its subject');
        }
    }

    public function test_an_institution_founding_cites_the_storms_that_drove_it(): void
    {
        // A larger, longer settlement outgrows its cohesion and founds the Temple from a deficit.
        $events = $this->chronicleOf('vaeris', 24, 40);
        $byType = static fn (string $type) => array_values(array_filter($events, static fn (ChronicleEvent $e): bool => $e->type === $type));

        $foundings = $byType('institution-founded');
        $this->assertNotEmpty($foundings, 'the settlement founds an institution');

        $underpreparedIds = array_map(static fn (ChronicleEvent $e): int => $e->id, $byType('sandstorm-underprepared'));
        foreach ($foundings as $founding) {
            $this->assertNotEmpty($founding->causes, 'a founding cites the storms that drove it');
            foreach ($founding->causes as $causeId) {
                $this->assertContains($causeId, $underpreparedIds, 'a founding cause is an underprepared-storm event');
            }
        }
    }

    /** @return list<ChronicleEvent> */
    private function chronicleOf(string $seed, int $population, int $years): array
    {
        $world = World::seedTharadosVillage(new Rng($seed), $population);
        $world->advance(self::TICKS_PER_YEAR * $years);

        return $world->chronicle->all();
    }
}
