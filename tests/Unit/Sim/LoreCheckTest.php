<?php

namespace Tests\Unit\Sim;

use App\Sim\Direction\LoreCheck;
use App\Sim\Direction\Waypoint;
use PHPUnit\Framework\TestCase;

/**
 * TWT-42: the lore consistency checker flags authored facts that can't all be
 * true — unsatisfiable in time, or contradictory pins — before they yield a
 * silently-broken world.
 */
class LoreCheckTest extends TestCase
{
    public function test_a_reachable_end_state_is_consistent(): void
    {
        $this->assertTrue(LoreCheck::isConsistent(new Waypoint('empire', 500)));
        $this->assertSame([], LoreCheck::check(new Waypoint('empire', 500)));
    }

    public function test_an_end_state_too_soon_is_flagged_with_a_reason(): void
    {
        $problems = LoreCheck::check(new Waypoint('empire', 50));

        $this->assertNotEmpty($problems);
        $this->assertFalse(LoreCheck::isConsistent(new Waypoint('empire', 50)));
        $this->assertStringContainsString('unsatisfiable', $problems[0]);
        $this->assertStringContainsString('before the world begins', $problems[0]);
    }

    public function test_contradictory_pins_are_flagged(): void
    {
        // The empire needs a town by Year 370, but the author pins the town only by Year 450.
        $problems = LoreCheck::check(new Waypoint('empire', 500), new Waypoint('town', 450));

        $this->assertFalse(LoreCheck::isConsistent(new Waypoint('empire', 500), new Waypoint('town', 450)));
        $this->assertNotEmpty(array_filter($problems, static fn (string $p): bool => str_contains($p, 'needs a town by Year 370')));
    }

    public function test_compatible_pins_are_consistent(): void
    {
        // A town authored earlier than the empire requires it is fine.
        $this->assertTrue(LoreCheck::isConsistent(new Waypoint('empire', 500), new Waypoint('town', 300)));
    }
}
