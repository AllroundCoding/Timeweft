<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\CirculationGenerator;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\Hydrology;
use App\Sim\Worldgen\HydrologyGenerator;
use App\Sim\Worldgen\SettlementSiter;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use PHPUnit\Framework\TestCase;

/**
 * TWT-82 — settlements emerge from the geography. Deterministic per seed; they sit on land, keep their
 * distance, and a world grows a spread of them.
 */
class SettlementSiterTest extends TestCase
{
    public function test_siting_is_deterministic(): void
    {
        $a = $this->sites($this->world());
        $b = $this->sites($this->world());

        $this->assertSame($a, $b, 'same seed → the same settlements in the same places at the same tiers');
        $this->assertNotEmpty($a, 'a habitable world grows settlements');
    }

    public function test_settlements_sit_on_land(): void
    {
        [$substrate, $climate, $hydrology] = $this->world();

        foreach (SettlementSiter::site($substrate, $climate, $hydrology) as $site) {
            $this->assertTrue($substrate->isLand($site->x, $site->y), 'a settlement sits on dry land');
        }
    }

    public function test_settlements_keep_their_distance(): void
    {
        [$substrate, $climate, $hydrology] = $this->world();
        $spacing = 6;
        $sites = SettlementSiter::site($substrate, $climate, $hydrology, $spacing);

        foreach ($sites as $i => $a) {
            foreach ($sites as $j => $b) {
                if ($j <= $i) {
                    continue;
                }
                $dx = abs($a->x - $b->x);
                $dx = min($dx, $substrate->width - $dx); // longitude wraps
                $this->assertGreaterThanOrEqual($spacing, hypot($dx, abs($a->y - $b->y)), 'settlements stay at least a spacing apart');
            }
        }
    }

    /** @param  array{0: Substrate, 1: Climate, 2: Hydrology}  $world @return list<array{int, int, string, float}> */
    private function sites(array $world): array
    {
        return array_map(
            static fn ($site): array => [$site->x, $site->y, $site->tier->value, $site->suitability],
            SettlementSiter::site($world[0], $world[1], $world[2]),
        );
    }

    /** @return array{0: Substrate, 1: Climate, 2: Hydrology} */
    private function world(string $seed = 'vaeris', int $width = 160, int $height = 100, int $plates = 12): array
    {
        $rng = new Rng($seed);
        $substrate = SubstrateGenerator::generate($rng, $width, $height, $plates);
        $circulation = CirculationGenerator::generate($rng, $substrate);
        $climate = ClimateGenerator::generate($rng, $substrate, $circulation);
        $hydrology = HydrologyGenerator::generate($substrate, $climate);

        return [$substrate, $climate, $hydrology];
    }
}
