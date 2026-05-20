<?php

namespace App\Console\Commands;

use App\Sim\Behavior\BehaviorEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('world:simulate {--ticks=48 : Number of ticks to advance (1 tick = 1 hour)} {--seed=vaeris : RNG seed for reproducible runs} {--population=5 : Number of founding villagers}')]
#[Description('Run the headless world simulation and dump the resulting chronicle')]
class WorldSimulate extends Command
{
    public function handle(): int
    {
        $ticks = (int) $this->option('ticks');
        $seed = (string) $this->option('seed');
        $population = (int) $this->option('population');

        $rng = new Rng($seed);
        $world = World::seedTharadosVillage($rng, $population);
        $sample = $world->village->agents[0];

        $this->info(sprintf('Timeweft — %s (%s), seed "%s"', $world->village->name, $world->village->region, $seed));
        $this->line('Founded: ' . TharadiCalendar::fromTick(0));
        $this->newLine();

        // Advance tick by tick, recording the sample agent's hourly activity.
        $grid = [];
        for ($i = 0; $i < $ticks; $i++) {
            $world->advance(1);
            $day = intdiv($world->tick, TharadiCalendar::HOURS_PER_DAY);
            $hour = $world->tick % TharadiCalendar::HOURS_PER_DAY;
            $grid[$day]['hours'][$hour] = $sample->activity->code();
            $grid[$day]['hunger'] = $sample->needs['hunger']->value;
            $grid[$day]['energy'] = $sample->needs['energy']->value;
        }

        $this->comment(sprintf(
            'Hourly activity of #%d %s — S sleep, E eat, W work, O social, H shelter, C celebrate:',
            $sample->id, $sample->name,
        ));
        $pad = str_repeat(' ', 14);
        $this->line($pad . implode('', array_map(fn ($h) => (string) intdiv($h, 10), range(0, 23))));
        $this->line($pad . implode('', array_map(fn ($h) => (string) ($h % 10), range(0, 23))));
        foreach ($grid as $day => $info) {
            $row = '';
            for ($h = 0; $h < 24; $h++) {
                $row .= $info['hours'][$h] ?? '·';
            }
            $date = TharadiCalendar::fromTick($day * TharadiCalendar::HOURS_PER_DAY);
            $this->line(sprintf('  %2d %-8s %s  h%3.0f e%3.0f', $date->dayOfMonth, $date->monthName, $row, $info['hunger'], $info['energy']));
        }
        $this->newLine();

        $this->comment('Season probe (midday routine, needs aside):');
        $oasisMidday = BehaviorEngine::routineActivity(TharadiCalendar::fromTick(13));
        $sandstormMidday = BehaviorEngine::routineActivity(TharadiCalendar::fromTick(24 * 120 + 13));
        $this->line(sprintf("  13:00 Oasis (Naralis): %s   |   13:00 Sandstorm (Ra'anis): %s", $oasisMidday->label(), $sandstormMidday->label()));
        $this->newLine();

        $this->comment('Chronicle:');
        $entries = $world->chronicle->all();
        if ($entries === []) {
            $this->line('  (no notable events yet)');
        }
        foreach ($entries as $entry) {
            $this->line('  ' . $entry['text']);
        }
        $this->newLine();

        $this->comment('Final roster:');
        $this->roster($world);

        return self::SUCCESS;
    }

    private function roster(World $world): void
    {
        foreach ($world->village->agents as $a) {
            $this->line(sprintf(
                '  #%d %-9s %s  age %2d  %-9s fur | agi %2.0f sen %2.0f dex %2.0f con %2.0f soc %2.0f heat %2.0f | hunger %3.0f energy %3.0f',
                $a->id,
                $a->name,
                $a->sex,
                $a->ageInYears($world->tick),
                $a->trait('furColor'),
                $a->trait('agility'),
                $a->trait('senses'),
                $a->trait('dexterity'),
                $a->trait('constitution'),
                $a->trait('sociability'),
                $a->trait('heatTolerance'),
                $a->needs['hunger']->value,
                $a->needs['energy']->value,
            ));
        }
    }
}
