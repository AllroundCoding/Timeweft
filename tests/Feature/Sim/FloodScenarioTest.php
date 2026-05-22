<?php

namespace Tests\Feature\Sim;

use App\Sim\Causality\EditHistory;
use App\Sim\Causality\Intervention;
use App\Sim\Causality\RetroactiveRipple;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Time\TharadiCalendar;
use PHPUnit\Framework\TestCase;

/**
 * TWT-37 — the "Lyrion's Great Flood" scenario: the end-to-end acceptance test
 * that the editing machinery (provenance → graph → ripple → edit log → undo)
 * works on a real narrative, not just at unit level.
 *
 * The flood stand-in is the most consequential catastrophe deep in a stressed
 * settlement's past. Author undoes it, and the consequences ripple: the
 * catastrophe and the history it caused are rewritten, while everything before
 * it is untouched — legible, reproducible, and reversible.
 */
class FloodScenarioTest extends TestCase
{
    private const SEED = 'vaeris';

    private const POPULATION = 24;

    private const YEARS = 40;

    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_undoing_the_great_flood_reshapes_the_downstream_history(): void
    {
        $history = new EditHistory;
        $trueHistory = $this->timeline($history);

        $flood = $this->mostConsequentialShock($trueHistory);
        $this->assertNotNull($flood, 'the stressed settlement suffers a catastrophe to undo');
        $floodYear = TharadiCalendar::fromTick($flood->tick)->year;

        $history->apply("undo the Great Flood of Year {$floodYear}", Intervention::suppressShocks($floodYear));
        $edited = $this->timeline($history);

        $result = RetroactiveRipple::replay(self::SEED, self::POPULATION, self::YEARS, Intervention::suppressShocks($floodYear));

        // The catastrophe is gone, and undoing it ripples far downstream — not just the event itself.
        $this->assertContains($flood->text, $this->texts($result->erased), 'the flood is erased');
        $this->assertGreaterThan(5, count($result->erased) + count($result->emerged), 'the edit ripples through downstream lives');
        $this->assertNotEmpty($result->emerged, 'lives the flood had prevented now emerge');

        // Legibility: every event before the flood is byte-identical — no butterfly chaos upstream.
        $this->assertSame(
            $this->textsBefore($trueHistory, $flood->tick),
            $this->textsBefore($edited, $flood->tick),
            'the past before the flood is untouched',
        );

        // The counterfactual is a living history, not a frozen one: events keep flowing after the edit.
        $this->assertTrue($this->hasEventAfter($edited, $flood->tick), 'history keeps turning after the edit');
    }

    public function test_undo_and_redo_restore_the_two_histories(): void
    {
        $history = new EditHistory;
        $trueHistory = $this->timeline($history);
        $flood = $this->mostConsequentialShock($trueHistory);
        $this->assertNotNull($flood);
        $floodYear = TharadiCalendar::fromTick($flood->tick)->year;

        $history->apply("undo the Great Flood of Year {$floodYear}", Intervention::suppressShocks($floodYear));
        $edited = $this->timeline($history);
        $this->assertNotSame($this->texts($trueHistory), $this->texts($edited));

        // Undo → the flood (and its history) returns, byte-identical to the original.
        $history->undo();
        $this->assertSame($this->texts($trueHistory), $this->texts($this->timeline($history)));

        // Redo → the counterfactual returns, byte-identical to the edited timeline.
        $history->redo();
        $this->assertSame($this->texts($edited), $this->texts($this->timeline($history)));
    }

    /** @return list<ChronicleEvent> */
    private function timeline(EditHistory $history): array
    {
        return RetroactiveRipple::canonicalTimeline(self::SEED, self::POPULATION, self::YEARS, $history->log());
    }

    /**
     * The past event whose undoing reshapes history the most — the Great Flood.
     *
     * @param  list<ChronicleEvent>  $events
     */
    private function mostConsequentialShock(array $events): ?ChronicleEvent
    {
        $best = null;
        $bestRipple = 0;
        $seenYears = [];
        foreach ($events as $event) {
            if (! str_starts_with($event->type, 'shock-')) {
                continue;
            }
            $year = TharadiCalendar::fromTick($event->tick)->year;
            if (isset($seenYears[$year])) {
                continue;
            }
            $seenYears[$year] = true;

            $result = RetroactiveRipple::replay(self::SEED, self::POPULATION, self::YEARS, Intervention::suppressShocks($year));
            $ripple = count($result->erased) + count($result->emerged);
            if ($ripple > $bestRipple) {
                $bestRipple = $ripple;
                $best = $event;
            }
        }

        return $best;
    }

    /** @param list<ChronicleEvent> $events */
    private function hasEventAfter(array $events, int $tick): bool
    {
        foreach ($events as $event) {
            if ($event->tick > $tick) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ChronicleEvent>  $events
     * @return list<string>
     */
    private function texts(array $events): array
    {
        return array_map(static fn (ChronicleEvent $e): string => $e->text, $events);
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
