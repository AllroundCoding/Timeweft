<?php

namespace Tests\Feature\Sim;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\RegionScheduler;
use App\Sim\World\Village;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-112 — domain decomposition. A single-region world runs identically to the undecomposed engine; a
 * multi-region world advances each region in isolation and merges back deterministically. The headline
 * guarantee: the merge is independent of the order the regions advance in — which is what lets a future
 * parallel run be byte-identical to the serial one.
 */
class RegionDecompositionTest extends TestCase
{
    private const YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_a_single_region_world_runs_identically_to_the_undecomposed_engine(): void
    {
        $scheduled = World::seedTharadosVillage(new Rng('vaeris'), 8);
        RegionScheduler::advance($scheduled, self::YEAR * 5);

        $direct = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $direct->advance(self::YEAR * 5);

        $this->assertSame($this->chronicle($direct), $this->chronicle($scheduled), 'one region decomposes to the plain run');
        $this->assertSame($this->roster($direct), $this->roster($scheduled), 'down to the same people');
    }

    public function test_a_multi_region_run_is_deterministic(): void
    {
        $a = $this->multiRegionWorld('vaeris');
        RegionScheduler::advance($a, self::YEAR * 4);

        $b = $this->multiRegionWorld('vaeris');
        RegionScheduler::advance($b, self::YEAR * 4);

        $this->assertSame($this->chronicle($a), $this->chronicle($b), 'same seed → same decomposed world');
        $this->assertSame($this->roster($a), $this->roster($b));
    }

    public function test_the_merge_is_independent_of_region_advance_order(): void
    {
        // The parallel-safety proof: advancing the regions in opposite orders must merge to the same world.
        $forward = $this->multiRegionWorld('khoradun');
        $forwardSubs = $forward->splitByRegion();
        foreach ($forwardSubs as $sub) {
            $sub->advance(self::YEAR * 4);
        }
        $forward->absorbRegions($forwardSubs);

        $reverse = $this->multiRegionWorld('khoradun');
        $reverseSubs = $reverse->splitByRegion();
        foreach (array_reverse($reverseSubs, true) as $sub) {
            $sub->advance(self::YEAR * 4);
        }
        $reverse->absorbRegions($reverseSubs);

        $this->assertSame($this->chronicle($forward), $this->chronicle($reverse), 'region advance order does not change the merged history');
        $this->assertSame($this->roster($forward), $this->roster($reverse), 'nor the people');
    }

    public function test_the_merge_keeps_every_settlement_and_leaves_no_id_collisions(): void
    {
        $world = $this->multiRegionWorld('vaeris');
        $before = array_map(static fn (Village $v): string => $v->name, $world->villages);
        sort($before);

        RegionScheduler::advance($world, self::YEAR * 3);

        $after = array_map(static fn (Village $v): string => $v->name, $world->villages);
        sort($after);
        $this->assertSame($before, $after, 'every settlement survives the merge');

        $ids = [];
        foreach ($world->villages as $village) {
            foreach ($village->agents as $agent) {
                $ids[] = $agent->id;
            }
        }
        $this->assertSame(count($ids), count(array_unique($ids)), 'no agent-id collisions across regions');
    }

    private function multiRegionWorld(string $seed): World
    {
        $world = World::seedTharadosVillage(new Rng($seed), 8);
        $world->villages[0]->regionId = 0;                 // Sunwell Oasis
        $world->foundVillage('Northvale')->regionId = 0;   // region 0: a pair that couples intra-region
        $world->foundVillage('Southreach')->regionId = 1;
        $world->foundVillage('Faredge')->regionId = 1;     // region 1: another pair

        return $world;
    }

    /** @return list<string> */
    private function chronicle(World $world): array
    {
        return array_map(static fn ($e): string => $e->text, $world->chronicle->all());
    }

    /** @return list<string> */
    private function roster(World $world): array
    {
        $rows = [];
        foreach ($world->villages as $village) {
            foreach ($village->livingAgents() as $agent) {
                $rows[] = sprintf('%d|%s|%s|%.1f', $agent->id, $agent->name, $agent->sex, $agent->trait('agility'));
            }
        }
        sort($rows);

        return $rows;
    }
}
