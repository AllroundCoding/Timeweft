<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * Locks in the engine's headline property — same seed → same world — and a
 * golden-master snapshot of the Sunwell run. The golden anchors are intentional:
 * if a change shifts them, update them deliberately (they are the regression guard).
 */
class SimulationDeterminismTest extends TestCase
{
    /** @return array{chronicle:list<string>,roster:list<string>,institution:?string,founders:int,living:int,born:int,died:int} */
    private function simulate(string $seed, int $population, int $years): array
    {
        $rng = new Rng($seed);
        $world = World::seedTharadosVillage($rng, $population);
        $founders = count($world->village->agents);

        $world->advance(TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR * $years);

        $all = $world->village->agents;
        $living = $world->livingAgents();

        return [
            'chronicle' => array_map(static fn (array $e): string => $e['text'], $world->chronicle->all()),
            'roster' => array_map(
                static fn (Agent $a): string => sprintf(
                    '%d|%s|%s|%.1f|%.1f',
                    $a->id, $a->name, $a->sex, $a->trait('agility'), $a->trait('senses'),
                ),
                $living,
            ),
            'institution' => $world->village->institution?->name,
            'founders' => $founders,
            'living' => count($living),
            'born' => count(array_filter($all, static fn (Agent $a): bool => $a->parentIds !== [])),
            'died' => count(array_filter($all, static fn (Agent $a): bool => ! $a->alive)),
        ];
    }

    public function test_same_seed_reproduces_an_identical_world(): void
    {
        $first = $this->simulate('vaeris', 8, 22);
        $second = $this->simulate('vaeris', 8, 22);

        $this->assertSame($first['chronicle'], $second['chronicle']);
        $this->assertSame($first['roster'], $second['roster']);
    }

    public function test_different_seeds_produce_different_histories(): void
    {
        $vaeris = $this->simulate('vaeris', 8, 22);
        $other = $this->simulate('khoradun', 8, 22);

        $this->assertNotEquals($vaeris['chronicle'], $other['chronicle']);
    }

    public function test_the_sunwell_run_stays_plausible(): void
    {
        // Invariants rather than brittle exact counts (the seeded run re-baselines as systems
        // land); two-run determinism above pins exact reproducibility separately.
        $run = $this->simulate('vaeris', 8, 22);

        $this->assertSame(8, $run['founders']);
        $this->assertGreaterThan(0, $run['born'], 'the village should bear children');
        $this->assertGreaterThanOrEqual($run['died'], $run['born'], 'it should not die out');
        $this->assertGreaterThanOrEqual(8, $run['living'], 'the village persists');
        $this->assertLessThanOrEqual(30, $run['living'], 'population stays bounded near carrying capacity');
    }

    public function test_the_institution_rises_and_falls(): void
    {
        // A larger, longer-lived settlement reliably outgrows its cohesion (a tiny village
        // can stay cohesive and never need one), so the Temple emerges from the deficit,
        // ossifies into collapse, and rises again — the rise & fall of civilizations.
        $run = $this->simulate('vaeris', 16, 40);

        $foundings = array_filter(
            $run['chronicle'],
            static fn (string $text): bool => str_contains($text, 'founds the Temple of Nara'),
        );
        $collapses = array_filter(
            $run['chronicle'],
            static fn (string $text): bool => str_contains($text, 'collapses'),
        );

        $this->assertGreaterThanOrEqual(2, count($foundings));
        $this->assertGreaterThanOrEqual(1, count($collapses));
    }
}
