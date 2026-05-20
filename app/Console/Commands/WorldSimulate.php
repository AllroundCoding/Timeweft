<?php

namespace App\Console\Commands;

use App\Sim\Time\TharadiCalendar;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('world:simulate {--ticks=12 : Number of ticks to advance the world (1 tick = 1 hour)} {--seed= : Optional RNG seed for reproducible runs}')]
#[Description('Run the headless world simulation and dump the resulting chronicle')]
class WorldSimulate extends Command
{
    public function handle(): int
    {
        $ticks = (int) $this->option('ticks');
        $seed = $this->option('seed');

        $this->info('Timeweft world simulation — Tharadi clock');
        $this->line(sprintf('  seed: %s', $seed ?? '(none)'));
        $this->newLine();

        $this->comment(sprintf('Advancing %d ticks from epoch (1 tick = 1 hour):', $ticks));
        for ($t = 0; $t < $ticks; $t++) {
            $this->line(sprintf('  tick %5d  %s', $t, TharadiCalendar::fromTick($t)));
        }
        $this->newLine();

        $this->comment('Calendar rollover checks:');
        $day = TharadiCalendar::HOURS_PER_DAY;
        foreach ([
            '+12 hours (half day)' => 12,
            '+1 day'   => $day,
            '+1 week'  => $day * TharadiCalendar::DAYS_PER_WEEK,
            '+1 month' => $day * TharadiCalendar::DAYS_PER_MONTH,
            'deep Sandstorm'       => $day * (TharadiCalendar::DAYS_PER_MONTH * 7 + 9),
            '+1 year'  => $day * TharadiCalendar::DAYS_PER_YEAR,
        ] as $label => $tick) {
            $this->line(sprintf('  %-22s tick %6d  %s', $label, $tick, TharadiCalendar::fromTick($tick)));
        }
        $this->newLine();

        $this->comment('Chronicle:');
        $this->line('  (empty — agents & events arrive in task #2+)');

        return self::SUCCESS;
    }
}
