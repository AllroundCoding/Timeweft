<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-51: the world holds and simulates multiple settlements, each run by the
 * same engine — the foundation multi-settlement trade and migration build on.
 */
class MultiSettlementTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_the_world_simulates_each_settlement(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $khoradun = $world->foundVillage('Khoradun', 6);
        $this->assertCount(2, $world->villages);

        $world->advance(self::TICKS_PER_YEAR * 15);

        // Both settlements lived through the run, with distinct populations.
        foreach ($world->villages as $village) {
            $this->assertNotEmpty($village->livingAgents(), "{$village->name} is still inhabited");
        }
        $sunwellIds = array_map(static fn (Agent $a): int => $a->id, $world->villages[0]->agents);
        $khoradunIds = array_map(static fn (Agent $a): int => $a->id, $world->villages[1]->agents);
        $this->assertSame([], array_values(array_intersect($sunwellIds, $khoradunIds)), 'settlements have distinct people');

        // The full engine ran on the *second* settlement too — it prepared for its own Sandstorms.
        $this->assertNotNull($khoradun->lastReadiness, 'the second settlement was simulated, not just the first');

        // The cursor is left on the primary settlement after the run.
        $this->assertSame($world->villages[0], $world->village);
    }

    public function test_a_multi_settlement_run_is_deterministic(): void
    {
        $this->assertSame($this->simulate(), $this->simulate());
    }

    /** @return array{chronicle:list<string>,living:list<int>} */
    private function simulate(): array
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->foundVillage('Khoradun', 6);
        $world->advance(self::TICKS_PER_YEAR * 15);

        return [
            'chronicle' => array_map(static fn (ChronicleEvent $e): string => $e->text, $world->chronicle->all()),
            'living' => array_map(static fn ($v): int => count($v->livingAgents()), $world->villages),
        ];
    }
}
