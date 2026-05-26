<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-45: in a running multi-settlement world, surplus flows along trade routes between settlements,
 * and the run stays deterministic. Trade is world-level and draws no RNG, so a single-settlement run
 * stays byte-identical (covered by the seeded golden-master elsewhere).
 */
class TradeTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_surplus_flows_between_settlements_over_a_run(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 16);
        $world->foundVillage('Breadbasket', 40, landYield: 80.0);       // a food-rich land
        $hungry = $world->foundVillage('Dusthold', 40, landYield: 8.0); // too many mouths for too little land
        $hungry->stockpile->withdraw('food', $hungry->stockpile->amount('food')); // and an empty granary to start

        $world->advance(self::TICKS_PER_YEAR * 20);

        $trades = array_filter($world->chronicle->all(), static fn (ChronicleEvent $e): bool => $e->type === 'trade');
        $this->assertNotEmpty($trades, 'a settlement that cannot feed itself draws grain from a flush neighbour');
    }

    public function test_a_trading_world_is_deterministic(): void
    {
        $this->assertSame($this->simulate(), $this->simulate());
    }

    /** @return array{chronicle:list<string>,living:list<int>} */
    private function simulate(): array
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 16);
        $world->foundVillage('Breadbasket', 40, landYield: 60.0);
        $world->foundVillage('Dusthold', 20, landYield: 10.0);
        $world->advance(self::TICKS_PER_YEAR * 20);

        return [
            'chronicle' => array_map(static fn (ChronicleEvent $e): string => $e->text, $world->chronicle->all()),
            'living' => array_map(static fn ($v): int => count($v->livingAgents()), $world->villages),
        ];
    }
}
