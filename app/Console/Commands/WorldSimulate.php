<?php

namespace App\Console\Commands;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\World;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('world:simulate {--ticks=12 : Number of ticks to advance (1 tick = 1 hour)} {--seed=vaeris : RNG seed for reproducible runs} {--population=5 : Number of founding villagers}')]
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

        $this->info(sprintf('Timeweft — %s (%s), seed "%s"', $world->village->name, $world->village->region, $seed));
        $this->line('Founded: ' . TharadiCalendar::fromTick($world->tick));
        $this->newLine();

        $this->comment('Villagers at founding:');
        $this->roster($world);
        $this->newLine();

        $world->advance($ticks);

        $this->comment(sprintf('After %d ticks → %s', $ticks, TharadiCalendar::fromTick($world->tick)));
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
