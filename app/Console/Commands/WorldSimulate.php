<?php

namespace App\Console\Commands;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('world:simulate {--years=22 : In-world years to simulate} {--seed=vaeris : RNG seed for reproducible runs} {--population=8 : Number of founding villagers}')]
#[Description('Run the headless world simulation and dump the resulting chronicle')]
class WorldSimulate extends Command
{
    public function handle(): int
    {
        $years = (int) $this->option('years');
        $seed = (string) $this->option('seed');
        $population = (int) $this->option('population');
        $ticks = $years * TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

        $rng = new Rng($seed);
        $world = World::seedTharadosVillage($rng, $population);
        $foundingCount = count($world->village->agents);

        $this->info(sprintf('Timeweft — %s (%s), seed "%s"', $world->village->name, $world->village->region, $seed));
        $this->line(sprintf('Founded %s; simulating %d years…', TharadiCalendar::fromTick(0), $years));
        $this->newLine();

        $world->advance($ticks);

        $this->comment('Chronicle:');
        foreach ($world->chronicle->all() as $entry) {
            $this->line('  ' . $entry['text']);
        }
        $this->newLine();

        $all = $world->village->agents;
        $born = count(array_filter($all, fn (Agent $a) => $a->parentIds !== []));
        $died = count(array_filter($all, fn (Agent $a) => ! $a->alive));
        $living = $world->livingAgents();
        $this->comment('Population:');
        $this->line(sprintf('  founders %d  ·  born %d  ·  died %d  ·  living now %d', $foundingCount, $born, $died, count($living)));
        $this->newLine();

        $this->inheritanceSpotlight($world, $all);

        $this->comment('Living roster:');
        foreach ($living as $a) {
            $partner = $a->partnerId !== null ? ($this->byId($all, $a->partnerId)?->name ?? '?') : '—';
            $origin = $a->parentIds !== []
                ? 'child of ' . implode(' & ', array_map(fn ($id) => $this->byId($all, $id)?->name ?? '?', $a->parentIds))
                : 'founder';
            $this->line(sprintf(
                '  #%-2d %-9s %s age %2d  ♥ %-9s  %-22s | agi %2.0f sen %2.0f heat %2.0f',
                $a->id, $a->name, $a->sex, $a->ageInYears($world->tick), $partner, $origin,
                $a->trait('agility'), $a->trait('senses'), $a->trait('heatTolerance'),
            ));
        }

        return self::SUCCESS;
    }

    /** @param list<Agent> $all */
    private function inheritanceSpotlight(World $world, array $all): void
    {
        $child = null;
        foreach ($all as $a) {
            if ($a->parentIds !== []) {
                $child = $a;
                break;
            }
        }
        if ($child === null) {
            return;
        }

        $mother = $this->byId($all, $child->parentIds[0]);
        $father = $this->byId($all, $child->parentIds[1]);
        if ($mother === null || $father === null) {
            return;
        }

        $this->comment(sprintf('Inheritance — %s, child of %s & %s:', $child->name, $mother->name, $father->name));
        foreach (['agility', 'senses', 'heatTolerance'] as $key) {
            $this->line(sprintf(
                '  %-13s %5.1f   ← mother %5.1f, father %5.1f',
                $key, $child->trait($key), $mother->trait($key), $father->trait($key),
            ));
        }
        $this->newLine();
    }

    /** @param list<Agent> $all */
    private function byId(array $all, int $id): ?Agent
    {
        foreach ($all as $a) {
            if ($a->id === $id) {
                return $a;
            }
        }

        return null;
    }
}
