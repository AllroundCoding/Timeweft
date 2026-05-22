<?php

namespace Tests\Feature\Sim;

use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\RegionArchetype;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-46: settlements founded in different region types *specialize* — a desert oasis grows dates
 * and herds goats, a temperate sownland grows orchard fruit and herbs — so each accumulates a
 * distinct basket of stores and is rich in different trade goods. The economic ground inter-settlement
 * trade (TWT-45) stands on. Regions are built from archetypes (the biome "guidelines"), not hardcoded.
 */
class RegionalSpecializationTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_desert_and_a_sownland_grow_distinct_baskets(): void
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 12); // the desert oasis
        $sownland = $world->foundVillage('Greenhollow', 8, landYield: 40.0, archetype: RegionArchetype::sownland());
        $desert = $world->villages[0];

        $world->advance(self::TICKS_PER_YEAR * 15);

        $this->assertNotEmpty($desert->livingAgents(), 'the desert oasis is still inhabited');
        $this->assertNotEmpty($sownland->livingAgents(), 'the sownland is still inhabited');

        // The desert grows dates and herds goats; it can never grow orchard fruit or herbs.
        $this->assertGreaterThan(0.0, $desert->stockpile->amount('dates'), 'the desert grows dates');
        $this->assertSame(0.0, $desert->stockpile->amount('fruit'), 'the desert grows no orchard fruit');
        $this->assertSame(0.0, $desert->stockpile->amount('herbs'), 'the desert grows no herbs');

        // The sownland grows fruit and herbs; it has neither dates nor herds.
        $this->assertGreaterThan(0.0, $sownland->stockpile->amount('fruit'), 'the sownland grows orchard fruit');
        $this->assertGreaterThan(0.0, $sownland->stockpile->amount('herbs'), 'the sownland grows herbs');
        $this->assertSame(0.0, $sownland->stockpile->amount('dates'), 'the sownland grows no dates');
        $this->assertSame(0.0, $sownland->stockpile->amount('goat meat'), 'the sownland herds no goats');

        // And they are rich in different trade goods — the seam trade reads to decide what flows where.
        $this->assertNotSame(
            $desert->regionProfile->resources(),
            $sownland->regionProfile->resources(),
            'the two biomes offer different specialties',
        );
        $this->assertContains('salt', $desert->regionProfile->resources());
        $this->assertContains('timber', $sownland->regionProfile->resources());
    }

    public function test_the_fertile_sownland_breeds_a_looser_culture_than_the_desert(): void
    {
        // Cultural materialism in the large: the harsh desert tightens values, the abundant sownland
        // loosens them — the same prediction RealismCheckTest checks against the canon, here emergent.
        $world = World::seedTharadosVillage(new Rng('vaeris'), 12);
        $sownland = $world->foundVillage('Greenhollow', 8, landYield: 40.0, archetype: RegionArchetype::sownland());
        $desert = $world->villages[0];

        $world->advance(self::TICKS_PER_YEAR * 15);

        $this->assertGreaterThan($sownland->culture->collectivism, $desert->culture->collectivism, 'the desert is the more collectivist');
        $this->assertGreaterThan($sownland->culture->restraint, $desert->culture->restraint, 'the desert is the more restrained');
        $this->assertLessThan($sownland->culture->achievement, $desert->culture->achievement, 'the sownland prizes achievement more');
    }

    public function test_a_specialized_world_is_deterministic(): void
    {
        $this->assertSame($this->simulate(), $this->simulate());
    }

    /** @return array{chronicle:list<string>,living:list<int>} */
    private function simulate(): array
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 12);
        $world->foundVillage('Greenhollow', 8, landYield: 40.0, archetype: RegionArchetype::sownland());
        $world->advance(self::TICKS_PER_YEAR * 15);

        return [
            'chronicle' => array_map(static fn (ChronicleEvent $e): string => $e->text, $world->chronicle->all()),
            'living' => array_map(static fn ($v): int => count($v->livingAgents()), $world->villages),
        ];
    }
}
