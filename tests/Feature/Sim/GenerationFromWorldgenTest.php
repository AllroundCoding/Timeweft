<?php

namespace Tests\Feature\Sim;

use App\Sim\Direction\Generation;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-82 pass 2 — the worldgen → sim bridge. Generation::fromWorldgen builds a live, multi-settlement
 * world from procedural geography (deterministically), and the same advance() engine that runs the
 * canonical seeded world runs this one too.
 */
class GenerationFromWorldgenTest extends TestCase
{
    private const YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_it_builds_a_multi_settlement_world_from_geography(): void
    {
        $world = $this->world();

        $this->assertGreaterThan(1, count($world->villages), 'a generated world holds many settlements');
        foreach ($world->villages as $village) {
            $this->assertNotSame('', $village->name, 'each settlement is named');
            $this->assertNotEmpty($village->agents, 'each settlement is founded with people');
        }
    }

    public function test_it_is_deterministic(): void
    {
        $this->assertSame(
            $this->fingerprint($this->world()),
            $this->fingerprint($this->world()),
            'same seed → the same settlements in the same places',
        );
    }

    public function test_the_generated_world_runs_on_the_same_engine(): void
    {
        $world = $this->world();
        $startTick = $world->tick;
        $world->advance(self::YEAR * 3);

        $this->assertGreaterThan($startTick, $world->tick, 'time moved forward');
        $this->assertNotEmpty($world->chronicle->all(), 'the generated world lives a history');
    }

    private function world(): World
    {
        return Generation::fromWorldgen(new Rng('vaeris'), 160, 100, 12, 20);
    }

    /** @return list<string> */
    private function fingerprint(World $world): array
    {
        return array_map(static fn ($village): string => $village->name.'@'.$village->x.','.$village->y, $world->villages);
    }
}
