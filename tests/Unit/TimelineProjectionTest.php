<?php

namespace Tests\Unit;

use App\Http\Timeline\TimelineProjection;
use App\Sim\Engine;
use App\Sim\Time\TharadiCalendar;
use PHPUnit\Framework\TestCase;

class TimelineProjectionTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    /** @return array<string,mixed> */
    private function project(int $years): array
    {
        return TimelineProjection::from(
            Engine::seed('vaeris', 8)->advance($years * self::TICKS_PER_YEAR),
        );
    }

    public function test_it_projects_the_axis_from_the_run_length(): void
    {
        $vm = $this->project(2);

        $this->assertSame(0, $vm['axis']['startTick']);
        $this->assertSame(2 * self::TICKS_PER_YEAR, $vm['axis']['endTick']);
        $this->assertSame(self::TICKS_PER_YEAR, $vm['axis']['ticksPerYear']);
        $this->assertSame(2, $vm['axis']['endYear'] - $vm['axis']['startYear']);
    }

    public function test_it_projects_the_full_cast_sorted_by_birth(): void
    {
        $vm = $this->project(3);

        // The full cast — founders plus anyone born — living and dead alike.
        $this->assertGreaterThanOrEqual(8, $vm['counts']['total']);
        $this->assertCount($vm['counts']['total'], $vm['lives']);
        $this->assertSame($vm['counts']['total'], $vm['counts']['living'] + $vm['counts']['died']);

        // Stable layout: lives are ordered by birth tick.
        $birthTicks = array_column($vm['lives'], 'birthTick');
        $sorted = $birthTicks;
        sort($sorted);
        $this->assertSame($sorted, $birthTicks);

        // A living agent has no death tick; a dead one does.
        foreach ($vm['lives'] as $life) {
            $this->assertSame($life['alive'], $life['deathTick'] === null);
        }
    }

    public function test_events_with_subjects_attach_to_lives_and_world_events_do_not(): void
    {
        $vm = $this->project(3);

        // Every world-lane event is subject-less by construction (text + a type, no row to pin to).
        foreach ($vm['world'] as $event) {
            $this->assertArrayHasKey('tick', $event);
            $this->assertArrayHasKey('type', $event);
            $this->assertArrayHasKey('text', $event);
        }

        // Births are subject-bearing, so at least one life carries a marker over a multi-year run.
        $markers = array_sum(array_map(static fn (array $life): int => count($life['events']), $vm['lives']));
        $this->assertGreaterThan(0, $markers);
    }

    public function test_it_is_deterministic_for_a_seed(): void
    {
        $this->assertEquals($this->project(2), $this->project(2));
    }
}
