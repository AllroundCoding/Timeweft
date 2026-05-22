<?php

namespace Tests\Unit\Sim;

use App\Sim\Behavior\Activity;
use App\Sim\Behavior\BehaviorEngine;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\Need;
use PHPUnit\Framework\TestCase;

/**
 * TWT-26: when an adult's workday goes to a communal project, the per-tick
 * activity reads "Contributing" instead of "Working" — visible participation —
 * without overriding the higher-priority needs above it.
 */
class BehaviorContributingTest extends TestCase
{
    private const WORK_HOUR_TICK = 5 * TharadiCalendar::HOURS_PER_DAY + 9; // Naralis (Oasis), 09:00 — a work hour

    public function test_contributing_replaces_working_when_a_project_is_open(): void
    {
        $agent = $this->agent();
        $date = TharadiCalendar::fromTick(self::WORK_HOUR_TICK);

        $this->assertSame(Activity::Working, BehaviorEngine::derive($agent, $date, false, false));
        $this->assertSame(Activity::Contributing, BehaviorEngine::derive($agent, $date, false, true));
    }

    public function test_contributing_only_replaces_work_not_other_activities(): void
    {
        $sleep = TharadiCalendar::fromTick(2 * TharadiCalendar::HOURS_PER_DAY + 2); // 02:00 — sleeping
        $this->assertSame(Activity::Sleeping, BehaviorEngine::derive($this->agent(), $sleep, false, true));
    }

    public function test_hunger_and_festivals_still_outrank_contributing(): void
    {
        $date = TharadiCalendar::fromTick(self::WORK_HOUR_TICK);

        $starving = $this->agent(hunger: 90.0);
        $this->assertSame(Activity::Eating, BehaviorEngine::derive($starving, $date, false, true));

        $this->assertSame(Activity::Celebrating, BehaviorEngine::derive($this->agent(), $date, true, true));
    }

    private function agent(float $hunger = 0.0): Agent
    {
        return new Agent(
            1, 'A', 'Vulpini', 'Tharados', 'f', -20 * TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR,
            ['agility' => 50.0],
            ['hunger' => new Need('hunger', $hunger, 100.0 / 16.0)],
        );
    }
}
