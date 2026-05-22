<?php

namespace Tests\Feature\Sim;

use App\Sim\Causality\EditLog;
use App\Sim\Causality\Intervention;
use App\Sim\Causality\RetroactiveRipple;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Time\TharadiCalendar;
use PHPUnit\Framework\TestCase;

/**
 * TWT-35: edits live in an append-only log as tombstonable entries (the author's
 * history), and the active ones fold into the replay that produces the canonical
 * timeline (the in-world history) — the substrate undo/redo is built on.
 */
class EditLogTest extends TestCase
{
    public function test_edits_are_recorded_in_order(): void
    {
        $log = new EditLog;
        $a = $log->record('undo the Year 1 blight', Intervention::suppressShocks(1, 'blight'));
        $b = $log->record('undo all raids', Intervention::suppressShocks(null, 'raid'));

        $this->assertSame(1, $a->id);
        $this->assertSame(2, $b->id);
        $this->assertCount(2, $log->all());
    }

    public function test_retracting_tombstones_without_removing(): void
    {
        $log = new EditLog;
        $edit = $log->record('undo the Year 1 blight', Intervention::suppressShocks(1, 'blight'));

        $log->retract($edit->id);
        $this->assertCount(1, $log->all(), 'the edit is kept for audit, not deleted');
        $this->assertSame([], $log->active(), 'a tombstoned edit is no longer in force');
        $this->assertTrue($log->all()[0]->retracted);

        $log->restore($edit->id);
        $this->assertCount(1, $log->active(), 'restoring lifts the tombstone');
    }

    public function test_active_edits_fold_into_one_intervention(): void
    {
        $log = new EditLog;
        $log->record('no shocks in Year 1', Intervention::suppressShocks(1));
        $blight = $log->record('no blights ever', Intervention::suppressShocks(null, 'blight'));

        $folded = $log->asIntervention();
        $this->assertTrue($folded->suppressesShock(1, 'raid'));    // first edit
        $this->assertTrue($folded->suppressesShock(7, 'blight'));  // second edit
        $this->assertFalse($folded->suppressesShock(7, 'plague')); // neither

        $log->retract($blight->id);
        $this->assertFalse($log->asIntervention()->suppressesShock(7, 'blight'), 'a tombstoned edit stops applying');
    }

    public function test_an_empty_log_suppresses_nothing(): void
    {
        $this->assertFalse((new EditLog)->asIntervention()->suppressesShock(1, 'blight'));
        $this->assertFalse(Intervention::none()->suppressesShock(1, 'blight'));
    }

    public function test_the_canonical_timeline_folds_active_edits_and_a_tombstone_reverses_it(): void
    {
        $log = new EditLog;
        $trueHistory = RetroactiveRipple::canonicalTimeline('vaeris', 8, 22, $log); // empty log = true history
        $shock = null;
        foreach ($trueHistory as $event) {
            if (str_starts_with($event->type, 'shock-')) {
                $shock = $event;
                break;
            }
        }
        $this->assertNotNull($shock);
        $shockYear = TharadiCalendar::fromTick($shock->tick)->year;

        $edit = $log->record("undo the Year {$shockYear} shock", Intervention::suppressShocks($shockYear));
        $edited = RetroactiveRipple::canonicalTimeline('vaeris', 8, 22, $log);
        $this->assertNotContains($shock->text, array_map(static fn (ChronicleEvent $e): string => $e->text, $edited));

        // Tombstone the edit → the canonical timeline returns to the true history.
        $log->retract($edit->id);
        $restored = RetroactiveRipple::canonicalTimeline('vaeris', 8, 22, $log);
        $this->assertSame(
            array_map(static fn (ChronicleEvent $e): string => $e->text, $trueHistory),
            array_map(static fn (ChronicleEvent $e): string => $e->text, $restored),
        );
    }
}
