<?php

namespace Tests\Feature\Sim;

use App\Sim\Culture\LegendEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-143 — myth & legend generation. Over a long, eventful run the chronicle's turning points pass into
 * a per-settlement corpus of legends: each traces to a real, legend-worthy event, each motif is kept once
 * (no duplicate spam), and old legends drift more mythic than recent ones. Deterministic per seed; the
 * canonical run stays byte-identical (pinned separately by SimulationDeterminismTest).
 */
class LegendEngineTest extends TestCase
{
    private const YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_long_run_mythologises_its_turning_points_each_remembered_once(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 24);
        $world->advance(self::YEAR * 40);

        $this->assertNotEmpty($world->legends, 'an eventful run yields legends');

        $eventsById = [];
        foreach ($world->chronicle->all() as $event) {
            $eventsById[$event->id] = $event;
        }

        $seen = [];
        $drifted = false;
        foreach ($world->legends as $legend) {
            // Traceable to a real event that was worth remembering.
            $this->assertArrayHasKey($legend->sourceEventId, $eventsById, 'a legend traces to a real chronicle event');
            $this->assertNotNull(LegendEngine::classify($eventsById[$legend->sourceEventId]->type), 'and that event was legend-worthy');
            $this->assertNotSame('', $legend->title);
            $this->assertNotSame('', $legend->telling);

            // One tale per motif per settlement — recurring events (the temple's rise & fall) don't duplicate.
            $key = $legend->motif.'@'.$legend->rememberedBy;
            $this->assertArrayNotHasKey($key, $seen, "the corpus keeps one legend per motif/place ({$key})");
            $seen[$key] = true;

            if ($legend->embellishment > 0.0) {
                $drifted = true;
            }
        }
        $this->assertTrue($drifted, 'older legends have drifted more mythic than their factual birth');
    }

    public function test_legends_are_deterministic(): void
    {
        $a = World::seedTharadosVillage(new Rng('vaeris'), 24);
        $a->advance(self::YEAR * 40);

        $b = World::seedTharadosVillage(new Rng('vaeris'), 24);
        $b->advance(self::YEAR * 40);

        $this->assertSame($this->corpus($a), $this->corpus($b), 'same seed → the same legends, word for word');
    }

    /** @return list<string> */
    private function corpus(World $world): array
    {
        return array_map(static fn ($legend): string => $legend->title.' :: '.$legend->telling, $world->legends);
    }
}
