<?php

namespace Tests\Feature\Sim;

use App\Sim\Causality\Intervention;
use App\Sim\Causality\RetroactiveRipple;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use PHPUnit\Framework\TestCase;

/**
 * TWT-34: edit a past event (undo a shock) and watch the consequences ripple —
 * recomputed by deterministic replay, legible because the seeded stream stays
 * aligned and only the edit's downstream cone diverges (design doc 09).
 */
class RetroactiveRippleTest extends TestCase
{
    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_undoing_a_shock_erases_it_and_only_ripples_forward(): void
    {
        $trueHistory = $this->timeline(null);
        $shock = $this->firstShock($trueHistory);
        $this->assertNotNull($shock, 'the seeded run records a shock to undo');
        $shockYear = TharadiCalendar::fromTick($shock->tick)->year;

        $result = RetroactiveRipple::replay('vaeris', 8, 22, Intervention::suppressShocks($shockYear));

        // The undone shock is gone from the recomputed history.
        $this->assertTrue($result->changed());
        $erasedText = array_map(static fn (ChronicleEvent $e): string => $e->text, $result->erased);
        $this->assertContains($shock->text, $erasedText);

        // Legibility: everything before the edit is byte-identical; only the cone forward diverges.
        $counterfactual = $this->timeline(Intervention::suppressShocks($shockYear));
        $this->assertSame(
            $this->textsBefore($trueHistory, $shock->tick),
            $this->textsBefore($counterfactual, $shock->tick),
            'history before the edit is untouched',
        );
        $this->assertGreaterThanOrEqual($shock->tick, $result->divergesAtTick);
    }

    public function test_an_edit_to_a_year_without_a_shock_changes_nothing(): void
    {
        $result = RetroactiveRipple::replay('vaeris', 8, 22, Intervention::suppressShocks(9999));

        $this->assertFalse($result->changed());
        $this->assertSame([], $result->erased);
        $this->assertSame([], $result->emerged);
        $this->assertNull($result->divergesAtTick);
    }

    public function test_the_ripple_is_deterministic(): void
    {
        $a = RetroactiveRipple::replay('vaeris', 8, 22, Intervention::suppressShocks());
        $b = RetroactiveRipple::replay('vaeris', 8, 22, Intervention::suppressShocks());

        $this->assertSame(
            array_map(static fn (ChronicleEvent $e): string => $e->text, $a->erased),
            array_map(static fn (ChronicleEvent $e): string => $e->text, $b->erased),
        );
        $this->assertSame($a->divergesAtTick, $b->divergesAtTick);
    }

    public function test_an_intervention_filters_by_year_and_type(): void
    {
        $this->assertTrue(Intervention::suppressShocks()->suppressesShock(3, 'blight'));
        $this->assertTrue(Intervention::suppressShocks(3)->suppressesShock(3, 'raid'));
        $this->assertFalse(Intervention::suppressShocks(3)->suppressesShock(4, 'raid'));
        $this->assertTrue(Intervention::suppressShocks(null, 'plague')->suppressesShock(9, 'plague'));
        $this->assertFalse(Intervention::suppressShocks(null, 'plague')->suppressesShock(9, 'blight'));
    }

    public function test_a_declared_cone_includes_the_event_and_its_descendants(): void
    {
        $trueHistory = $this->timeline(null);
        $pairing = $this->firstOfType($trueHistory, 'pairing');
        $birth = $this->firstOfType($trueHistory, 'birth');
        $this->assertNotNull($pairing);

        $cone = RetroactiveRipple::declaredConeOf($trueHistory, $pairing->id);
        $coneIds = array_map(static fn (ChronicleEvent $e): int => $e->id, $cone);

        $this->assertContains($pairing->id, $coneIds, 'the cone includes the edited event itself');
        if ($birth !== null && $birth->causes === [$pairing->id]) {
            $this->assertContains($birth->id, $coneIds, 'the cone reaches the birth the union produced');
        }
    }

    /** @return list<ChronicleEvent> */
    private function timeline(?Intervention $edit): array
    {
        $world = World::seedTharadosVillage(new Rng('vaeris'), 8);
        $world->intervention = $edit;
        $world->advance(self::TICKS_PER_YEAR * 22);

        return $world->chronicle->all();
    }

    /** @param list<ChronicleEvent> $events */
    private function firstShock(array $events): ?ChronicleEvent
    {
        foreach ($events as $event) {
            if (str_starts_with($event->type, 'shock-')) {
                return $event;
            }
        }

        return null;
    }

    /** @param list<ChronicleEvent> $events */
    private function firstOfType(array $events, string $type): ?ChronicleEvent
    {
        foreach ($events as $event) {
            if ($event->type === $type) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @param  list<ChronicleEvent>  $events
     * @return list<string>
     */
    private function textsBefore(array $events, int $tick): array
    {
        $out = [];
        foreach ($events as $event) {
            if ($event->tick < $tick) {
                $out[] = $event->text;
            }
        }

        return $out;
    }
}
