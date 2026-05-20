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

    public function test_golden_master_sunwell_run(): void
    {
        $run = $this->simulate('vaeris', 8, 22);

        $this->assertSame(8, $run['founders']);
        $this->assertSame(7, $run['born']);
        $this->assertSame(1, $run['died']);
        $this->assertSame(14, $run['living']);
        $this->assertSame('Temple of Nara', $run['institution']);
    }

    public function test_the_institution_rises_falls_and_rises_again(): void
    {
        $run = $this->simulate('vaeris', 8, 22);

        $foundings = array_values(array_filter(
            $run['chronicle'],
            static fn (string $text): bool => str_contains($text, 'founds the Temple of Nara'),
        ));
        $collapses = array_values(array_filter(
            $run['chronicle'],
            static fn (string $text): bool => str_contains($text, 'collapses'),
        ));

        // First Temple in Year 11, then it ossifies and collapses, and a new one rises — rise & fall.
        $this->assertStringContainsString('Year 11', $foundings[0]);
        $this->assertGreaterThanOrEqual(2, count($foundings));
        $this->assertNotEmpty($collapses);
    }
}
