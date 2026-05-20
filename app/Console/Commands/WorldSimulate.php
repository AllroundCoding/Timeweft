<?php

namespace App\Console\Commands;

use App\Sim\Projects\ProjectEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\World;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('world:simulate {--years=22 : In-world years to simulate} {--seed=vaeris : RNG seed for reproducible runs} {--population=8 : Number of founding villagers} {--json : Also write the chronicle + roster to storage/app/chronicle.json}')]
#[Description('Run the headless world simulation and dump the resulting chronicle')]
class WorldSimulate extends Command
{
    public function handle(): int
    {
        $years = (int) $this->option('years');
        $seed = (string) $this->option('seed');
        $population = (int) $this->option('population');

        $rng = new Rng($seed);
        $world = World::seedTharadosVillage($rng, $population);
        $foundingCount = count($world->village->agents);

        $this->info(sprintf('Timeweft — %s (%s), seed "%s"', $world->village->name, $world->village->region, $seed));
        $this->line(sprintf('Founded %s; simulating %d years…', TharadiCalendar::fromTick(0), $years));
        $this->newLine();

        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;
        $popSeries = [];
        for ($y = 1; $y <= $years; $y++) {
            $world->advance($ticksPerYear);
            $popSeries[] = count($world->livingAgents());
        }

        $this->comment('Chronicle:');
        foreach ($world->chronicle->all() as $entry) {
            $this->line('  '.$entry['text']);
        }
        $this->newLine();

        $all = $world->village->agents;
        $born = count(array_filter($all, fn (Agent $a) => $a->parentIds !== []));
        $died = count(array_filter($all, fn (Agent $a) => ! $a->alive));
        $living = $world->livingAgents();
        $this->comment('Population:');
        $this->line(sprintf('  founders %d  ·  born %d  ·  died %d  ·  living now %d', $foundingCount, $born, $died, count($living)));
        $this->line(sprintf(
            '  trajectory %s  Y1=%d … Y%d=%d  (peak %d, carrying capacity %d)',
            $this->sparkline($popSeries),
            $popSeries[0] ?? 0,
            $years,
            $popSeries[array_key_last($popSeries)] ?? 0,
            $popSeries === [] ? 0 : max($popSeries),
            $world->village->carryingCapacity,
        ));
        $this->newLine();

        $this->comment('Milestones (story director):');
        foreach ($world->milestones as $m) {
            if ($m->achieved) {
                $achievedDate = TharadiCalendar::fromTick((int) $m->achievedTick);
                $this->line(sprintf(
                    '  ✓ %s — Year %d %s (budget: by Year %d)',
                    ucfirst($m->name),
                    $achievedDate->year,
                    $m->wasForced ? '[forced as the deadline arrived]' : '[emerged organically]',
                    $m->deadlineYear,
                ));
            } else {
                $this->line(sprintf('  ✗ %s — unfulfilled (budget: by Year %d)', ucfirst($m->name), $m->deadlineYear));
            }
        }
        $this->newLine();

        $this->comment('Cooperation — Sandstorm preparation:');
        $village = $world->village;
        $cohesion = $village->cohesion(count($living));
        $this->line(sprintf(
            '  cohesion %.2f  ·  latest readiness %s  ·  underprepared years %d',
            $cohesion,
            $village->lastReadiness !== null ? ((int) round($village->lastReadiness * 100)).'%' : 'n/a',
            $village->underpreparedYears,
        ));
        $culture = $village->culture;
        $this->line(sprintf(
            '  culture: %s — collectivism %d · hierarchy %d · tradition %d · restraint %d · piety %d',
            $culture->name, (int) $culture->collectivism, (int) $culture->hierarchy,
            (int) $culture->tradition, (int) $culture->restraint, (int) $culture->piety,
        ));
        $this->line(sprintf(
            '  baseline %.2f (from collectivism) decays with scale → %.2f at %d souls (floor %.2f, group size %d)',
            $village->baselineCohesion, $cohesion, count($living), $village->cohesionFloor, $village->cohesiveGroupSize,
        ));
        if ($village->institution !== null) {
            $inst = $village->institution;
            $this->line(sprintf(
                '  institution: %s (%s), founded Year %d — compels participation (mandate %d%%)',
                $inst->name, $inst->type, TharadiCalendar::fromTick($inst->foundedTick)->year, (int) round($inst->mandate * 100),
            ));
        } else {
            $this->line('  institution: none — organic cohesion still suffices');
        }
        $this->line('  participation weight = want-to (cohesion × sociability) lifted by the institution:');
        $adults = array_slice(
            array_values(array_filter($living, fn (Agent $a) => $a->ageInYears($world->tick) >= 16)),
            0,
            6,
        );
        foreach ($adults as $a) {
            $this->line(sprintf(
                '    %-9s soc %2.0f → %.2f effort/day',
                $a->name, $a->trait('sociability'), ProjectEngine::participationWeight($a, $cohesion, $village->institution),
            ));
        }
        $this->newLine();

        $this->inheritanceSpotlight($world, $all);

        $this->comment('Living roster:');
        foreach ($living as $a) {
            $partner = $a->partnerId !== null ? ($this->byId($all, $a->partnerId)?->name ?? '?') : '—';
            $origin = $a->parentIds !== []
                ? 'child of '.implode(' & ', array_map(fn ($id) => $this->byId($all, $id)?->name ?? '?', $a->parentIds))
                : 'founder';
            $this->line(sprintf(
                '  #%-2d %-9s %s age %2d  ♥ %-9s  %-22s | agi %2.0f sen %2.0f heat %2.0f',
                $a->id, $a->name, $a->sex, $a->ageInYears($world->tick), $partner, $origin,
                $a->trait('agility'), $a->trait('senses'), $a->trait('heatTolerance'),
            ));
        }

        if ($this->option('json')) {
            $this->writeJson($world, $seed, $years, $foundingCount, $born, $died, $living);
        }

        return self::SUCCESS;
    }

    /** @param list<Agent> $living */
    private function writeJson(World $world, string $seed, int $years, int $foundingCount, int $born, int $died, array $living): void
    {
        $payload = [
            'world' => $world->village->name,
            'region' => $world->village->region,
            'seed' => $seed,
            'years' => $years,
            'population' => [
                'founders' => $foundingCount,
                'born' => $born,
                'died' => $died,
                'living' => count($living),
            ],
            'culture' => [
                'name' => $world->village->culture->name,
                'vector' => $world->village->culture->vector(),
            ],
            'institution' => $world->village->institution !== null ? [
                'name' => $world->village->institution->name,
                'type' => $world->village->institution->type,
                'foundedYear' => TharadiCalendar::fromTick($world->village->institution->foundedTick)->year,
                'mandate' => $world->village->institution->mandate,
            ] : null,
            'milestones' => array_map(fn ($m) => [
                'name' => $m->name,
                'deadlineYear' => $m->deadlineYear,
                'prereqPopulation' => $m->prereqPopulation,
                'achieved' => $m->achieved,
                'achievedYear' => $m->achievedTick !== null ? TharadiCalendar::fromTick($m->achievedTick)->year : null,
                'forced' => $m->wasForced,
            ], $world->milestones),
            'chronicle' => $world->chronicle->all(),
            'roster' => array_map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'sex' => $a->sex,
                'age' => $a->ageInYears($world->tick),
                'partnerId' => $a->partnerId,
                'parentIds' => $a->parentIds,
                'traits' => $a->traits,
                'needs' => array_map(fn ($n) => round($n->value, 1), $a->needs),
            ], $living),
        ];

        $path = storage_path('app/chronicle.json');
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info("Chronicle written to {$path}");
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

    /** @param list<int> $values */
    private function sparkline(array $values): string
    {
        if ($values === []) {
            return '';
        }
        $bars = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];
        $max = max($values);
        $out = '';
        foreach ($values as $v) {
            $out .= $bars[$max > 0 ? (int) round($v / $max * (count($bars) - 1)) : 0];
        }

        return $out;
    }
}
