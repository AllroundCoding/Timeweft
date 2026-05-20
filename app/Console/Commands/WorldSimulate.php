<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('world:simulate {--ticks=10 : Number of ticks to advance the world} {--seed= : Optional RNG seed for reproducible runs}')]
#[Description('Run the headless world simulation and dump the resulting chronicle')]
class WorldSimulate extends Command
{
    public function handle(): int
    {
        $ticks = (int) $this->option('ticks');
        $seed = $this->option('seed');

        $this->info('Timeweft world simulation — skeleton');
        $this->line(sprintf('  ticks: %d', $ticks));
        $this->line(sprintf('  seed:  %s', $seed ?? '(none)'));
        $this->newLine();

        $this->comment('Chronicle:');
        $this->line('  (empty — simulation engine not yet implemented)');

        return self::SUCCESS;
    }
}
