<?php

namespace Tests\Feature\Sim;

use App\Sim\Causality\CausalGraph;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-114: the chronicle now records the economic/environmental turning points
 * (technological advance, exhausted land, lean/bumper harvests) and a famine
 * cites the material drivers behind it — so the causal graph traces a famine's
 * deaths all the way back to the land or harvest that caused them.
 */
class MaterialCausesTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    private const MATERIAL_CAUSES = ['land-exhausted', 'harvest-lean', 'shock-blight'];

    public function test_the_rise_is_legible_in_the_chronicle(): void
    {
        // A viable settlement chronicles its technological advances — the rise, not only the fall.
        $events = $this->chronicleOf('vaeris', 8, 40);

        $this->assertNotEmpty($this->ofType($events, 'tech-advance'), 'a thriving settlement records its advances');
    }

    public function test_material_drivers_are_chronicled_and_famines_cite_them(): void
    {
        // A stressed, overshooting settlement: the land exhausts, harvests swing, famines follow.
        $events = $this->chronicleOf('vaeris', 24, 40);
        $byId = $this->byId($events);

        $this->assertNotEmpty($this->ofType($events, 'land-exhausted'), 'overuse exhausts the land, and it is chronicled');
        $this->assertNotEmpty($this->ofType($events, 'harvest-lean'), 'lean years are chronicled');

        $famines = $this->ofType($events, 'famine-onset');
        $this->assertNotEmpty($famines);

        $explained = 0;
        foreach ($famines as $famine) {
            foreach ($famine->causes as $causeId) {
                $this->assertContains($byId[$causeId]->type, self::MATERIAL_CAUSES, 'a famine cause is a material driver');
                $explained++;
            }
        }
        $this->assertGreaterThan(0, $explained, 'at least one famine is attributed to its material cause');
    }

    public function test_exhausting_the_land_causally_reaches_the_deaths_it_drives(): void
    {
        // The end-to-end chain doc 09 promises: a material driver → famine → death.
        $events = $this->chronicleOf('vaeris', 24, 40);
        $graph = new CausalGraph($events);

        $landExhausted = $this->ofType($events, 'land-exhausted');
        $this->assertNotEmpty($landExhausted);

        $reachesADeath = false;
        foreach ($landExhausted as $event) {
            foreach ($graph->events($graph->downstreamCone($event->id)) as $downstream) {
                if ($downstream->type === 'death') {
                    $reachesADeath = true;
                }
            }
        }
        $this->assertTrue($reachesADeath, 'the exhausted land causally reaches a famine death');
    }

    /** @return list<ChronicleEvent> */
    private function chronicleOf(string $seed, int $population, int $years): array
    {
        $world = World::seedTharadosVillage(new Rng($seed), $population);
        $world->advance(self::TICKS_PER_YEAR * $years);

        return $world->chronicle->all();
    }

    /**
     * @param  list<ChronicleEvent>  $events
     * @return list<ChronicleEvent>
     */
    private function ofType(array $events, string $type): array
    {
        return array_values(array_filter($events, static fn (ChronicleEvent $e): bool => $e->type === $type));
    }

    /**
     * @param  list<ChronicleEvent>  $events
     * @return array<int,ChronicleEvent>
     */
    private function byId(array $events): array
    {
        $byId = [];
        foreach ($events as $event) {
            $byId[$event->id] = $event;
        }

        return $byId;
    }
}
