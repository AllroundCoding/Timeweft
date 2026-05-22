<?php

namespace Tests\Feature\Sim;

use App\Sim\Causality\EditHistory;
use App\Sim\Causality\Intervention;
use App\Sim\Causality\RetroactiveRipple;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Time\TharadiCalendar;
use PHPUnit\Framework\TestCase;

/**
 * TWT-36: undo/redo over the edit log — linear (walk the most-recent edits) and
 * selective (drop one past edit, keep the later ones, rebase-style).
 */
class EditHistoryTest extends TestCase
{
    public function test_linear_undo_and_redo_walk_the_recent_edits(): void
    {
        $history = new EditHistory;
        $history->apply('A', Intervention::suppressShocks(1));
        $history->apply('B', Intervention::suppressShocks(2));
        $history->apply('C', Intervention::suppressShocks(3));
        $this->assertActiveNotes(['A', 'B', 'C'], $history);

        $this->assertSame('C', $history->undo()?->note);
        $this->assertSame('B', $history->undo()?->note);
        $this->assertActiveNotes(['A'], $history);
        $this->assertTrue($history->canRedo());

        $this->assertSame('B', $history->redo()?->note); // redo is LIFO over undo
        $this->assertActiveNotes(['A', 'B'], $history);
    }

    public function test_nothing_to_undo_or_redo_returns_null(): void
    {
        $history = new EditHistory;
        $this->assertNull($history->undo());
        $this->assertNull($history->redo());
        $this->assertFalse($history->canUndo());
        $this->assertFalse($history->canRedo());

        $history->apply('A', Intervention::suppressShocks(1));
        $history->undo(); // A tombstoned
        $this->assertFalse($history->canUndo());
        $this->assertTrue($history->canRedo());
        $this->assertSame('A', $history->redo()?->note);
    }

    public function test_a_new_edit_forks_history_and_clears_redo(): void
    {
        $history = new EditHistory;
        $history->apply('A', Intervention::suppressShocks(1));
        $history->apply('B', Intervention::suppressShocks(2));
        $history->undo(); // B undone, redo available

        $this->assertTrue($history->canRedo());
        $history->apply('C', Intervention::suppressShocks(3)); // forks
        $this->assertFalse($history->canRedo(), 'a new edit drops the redo stack');
        $this->assertActiveNotes(['A', 'C'], $history);
    }

    public function test_selective_undo_keeps_the_later_edits(): void
    {
        $history = new EditHistory;
        $a = $history->apply('no shocks Y1', Intervention::suppressShocks(1));
        $b = $history->apply('no shocks Y5', Intervention::suppressShocks(5));
        $c = $history->apply('no shocks Y9', Intervention::suppressShocks(9));

        $history->undoEdit($b->id); // rebase out the middle edit

        $folded = $history->log()->asIntervention();
        $this->assertTrue($folded->suppressesShock(1, 'blight'));   // A kept
        $this->assertFalse($folded->suppressesShock(5, 'blight'));  // B dropped
        $this->assertTrue($folded->suppressesShock(9, 'blight'));   // C kept
        $this->assertActiveNotes(['no shocks Y1', 'no shocks Y9'], $history);
        $this->assertSame([$a->id, $c->id], array_map(static fn ($e) => $e->id, $history->log()->active()));
    }

    public function test_undo_and_redo_round_trip_the_canonical_timeline(): void
    {
        $history = new EditHistory;
        $trueHistory = RetroactiveRipple::canonicalTimeline('vaeris', 8, 22, $history->log());
        $shock = null;
        foreach ($trueHistory as $event) {
            if (str_starts_with($event->type, 'shock-')) {
                $shock = $event;
                break;
            }
        }
        $this->assertNotNull($shock);
        $shockYear = TharadiCalendar::fromTick($shock->tick)->year;

        $history->apply("undo Year {$shockYear}", Intervention::suppressShocks($shockYear));
        $this->assertNotContains($shock->text, $this->texts(RetroactiveRipple::canonicalTimeline('vaeris', 8, 22, $history->log())));

        $history->undo();
        $this->assertContains($shock->text, $this->texts(RetroactiveRipple::canonicalTimeline('vaeris', 8, 22, $history->log())));

        $history->redo();
        $this->assertNotContains($shock->text, $this->texts(RetroactiveRipple::canonicalTimeline('vaeris', 8, 22, $history->log())));
    }

    private function assertActiveNotes(array $expected, EditHistory $history): void
    {
        $this->assertSame($expected, array_map(static fn ($e) => $e->note, $history->log()->active()));
    }

    /**
     * @param  list<ChronicleEvent>  $events
     * @return list<string>
     */
    private function texts(array $events): array
    {
        return array_map(static fn (ChronicleEvent $e): string => $e->text, $events);
    }
}
