<?php

namespace Tests\Unit\Sim;

use App\Sim\Support\Rng;
use App\Sim\World\RegionPartition;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-112: settlements carry a regionId; the partition groups them in a fixed (ascending) order — the
 * schedule the decomposition advances regions in. A default world is a single region, so the canonical
 * run is one region and unchanged.
 */
class RegionPartitionTest extends TestCase
{
    public function test_a_default_world_is_a_single_region(): void
    {
        $world = World::seedTharadosVillage(new Rng('partition'), 8);
        $world->foundVillage('Khoradun');

        $regions = RegionPartition::regionsOf($world);

        $this->assertSame([0], array_keys($regions), 'every settlement defaults to region 0');
        $this->assertCount(2, $regions[0]);
    }

    public function test_settlements_group_by_region_in_ascending_order(): void
    {
        $world = World::seedTharadosVillage(new Rng('partition'), 8);
        $far = $world->foundVillage('Far');
        $far->regionId = 2;
        $near = $world->foundVillage('Near');
        $near->regionId = 1;

        $regions = RegionPartition::regionsOf($world);

        $this->assertSame([0, 1, 2], array_keys($regions), 'regions iterate in ascending id — the fixed barrier schedule');
        $this->assertSame('Near', $regions[1][0]->name);
        $this->assertSame('Far', $regions[2][0]->name);
    }

    public function test_same_region_distinguishes_intra_from_cross_region_pairs(): void
    {
        $world = World::seedTharadosVillage(new Rng('partition'), 8);
        $a = $world->villages[0];
        $b = $world->foundVillage('B');
        $c = $world->foundVillage('C');
        $b->regionId = 1;
        $c->regionId = 1;

        $this->assertTrue(RegionPartition::sameRegion($b, $c), 'same region → intra (daily, exact)');
        $this->assertFalse(RegionPartition::sameRegion($a, $b), 'different region → cross (batched at the barrier)');
    }
}
