<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\World\RegionArchetype;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-120: a settlement founded without a name is christened in its own culture's voice, and the
 * culture itself is sourced from the region rather than a hardcoded 'Tharadi' fallback. Canon
 * scenarios still pin authored names by passing them.
 */
class GeneratedSettlementNameTest extends TestCase
{
    public function test_an_unnamed_settlement_is_christened_from_its_culture(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 6);

        $village = $world->foundVillage(population: 4); // no name given

        $this->assertNotSame('', $village->name, 'a name is coined');
        $this->assertNotSame('Sunwell Oasis', $village->name, 'and it is its own, not the primary settlement');
        // The culture is sourced from the (Tharados) region — not a hardcoded fallback string.
        $this->assertSame('Tharadi', $village->culture->name);
    }

    public function test_a_sownland_settlement_is_christened_in_the_aetherian_voice(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 6);

        $village = $world->foundVillage(population: 4, archetype: RegionArchetype::sownland());

        $this->assertNotSame('', $village->name);
        $this->assertSame('Aetherian', $village->culture->name, 'culture follows the archetype, not a fallback');
    }

    public function test_a_passed_name_is_pinned_for_canon_scenarios(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 6);

        $village = $world->foundVillage('Khoradun', 4);

        $this->assertSame('Khoradun', $village->name);
    }

    public function test_founding_is_deterministic(): void
    {
        $name = static function (): string {
            $world = World::seedTharadosVillage(new Rng('vaeris'), 6);

            return $world->foundVillage(population: 4)->name;
        };

        $this->assertSame($name(), $name());
    }
}
