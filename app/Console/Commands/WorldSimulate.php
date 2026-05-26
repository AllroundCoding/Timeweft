<?php

namespace App\Console\Commands;

use App\Sim\Direction\Generation;
use App\Sim\Direction\LoreCheck;
use App\Sim\Direction\StoryDirector;
use App\Sim\Direction\Waypoint;
use App\Sim\Economy\EconomyEngine;
use App\Sim\Projects\ProjectEngine;
use App\Sim\Support\Rng;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use App\Sim\World\Village;
use App\Sim\World\World;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('world:simulate {--years=22 : In-world years to simulate} {--seed=vaeris : RNG seed for reproducible runs} {--population=8 : Number of founding villagers} {--end-state= : Justify an authored end-state instead of surprising me — e.g. empire@500 or empire@500,town@300} {--worldgen : Generate the world from procedural geography (TWT-82) instead of the seeded village} {--json : Also write the chronicle + roster to storage/app/chronicle.json}')]
#[Description('Run the headless world simulation and dump the resulting chronicle')]
class WorldSimulate extends Command
{
    public function handle(): int
    {
        $years = (int) $this->option('years');
        $seed = (string) $this->option('seed');
        $population = (int) $this->option('population');
        $endStateSpec = (string) $this->option('end-state');

        $rng = new Rng($seed);

        if ($this->option('worldgen')) {
            $world = Generation::fromWorldgen($rng);
        } elseif ($endStateSpec !== '') {
            $waypoints = self::parseEndState($endStateSpec);
            $problems = LoreCheck::check(...$waypoints);
            if ($problems !== []) {
                $this->error('Inconsistent lore — cannot generate this world:');
                foreach ($problems as $problem) {
                    $this->line('  • '.$problem);
                }

                return self::FAILURE;
            }
            $world = Generation::fromEndState($rng, $population, ...$waypoints);
        } else {
            $world = Generation::seedForward($rng, $population);
        }
        $foundingCount = array_sum(array_map(static fn (Village $v): int => count($v->agents), $world->villages));

        $this->info(sprintf('Timeweft — %s (%s), seed "%s"', $world->village->name, $world->village->region, $seed));
        $this->line(match (true) {
            (bool) $this->option('worldgen') => sprintf('Generation mode: worldgen — %d settlements sited on procedural geography', count($world->villages)),
            $endStateSpec !== '' => sprintf('Generation mode: end-state-backward — justifying %s', $endStateSpec),
            default => 'Generation mode: seed-forward — surprise me',
        });
        $this->line(sprintf('Founded %s; simulating %d years…', TharadiCalendar::fromTick(0), $years));
        $this->newLine();

        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;
        $popSeries = [];
        for ($y = 1; $y <= $years; $y++) {
            $world->advance($ticksPerYear);
            $popSeries[] = $world->livingPopulation();
        }

        $this->comment('Chronicle:');
        foreach ($world->chronicle->all() as $entry) {
            $this->line('  '.$entry->text);
        }
        $this->newLine();

        $this->comment('Legends — what the chronicle became (myth & memory):');
        if ($world->legends === []) {
            $this->line('  (none yet — a longer, more eventful run mythologizes its turning points)');
        } else {
            foreach ($world->legends as $legend) {
                $this->line(sprintf('  "%s" — %s', $legend->title, $legend->telling));
            }
        }
        $this->newLine();

        $all = $world->village->agents;
        $worldAgents = $world->villages === [] ? $all : array_merge(...array_map(static fn (Village $v): array => $v->agents, $world->villages));
        $born = count(array_filter($worldAgents, fn (Agent $a) => $a->parentIds !== []));
        $died = count(array_filter($worldAgents, fn (Agent $a) => ! $a->alive));
        $living = $world->livingAgents();
        $this->comment('Population:');
        $this->line(sprintf('  founders %d  ·  born %d  ·  died %d  ·  living now %d', $foundingCount, $born, $died, $world->livingPopulation()));
        $this->line(sprintf(
            '  trajectory %s  Y1=%d … Y%d=%d  (peak %d, carrying capacity %d)',
            $this->sparkline($popSeries),
            $popSeries[0] ?? 0,
            $years,
            $popSeries[array_key_last($popSeries)] ?? 0,
            $popSeries === [] ? 0 : max($popSeries),
            array_sum(array_map(static fn (Village $v): int => $v->carryingCapacity, $world->villages)),
        ));
        if (count($world->villages) > 1) {
            $this->line('  settlements (population / capacity — a world spreads its fate across many, not one):');
            $ranked = $world->villages;
            usort($ranked, static fn (Village $a, Village $b): int => $b->headcount() <=> $a->headcount());
            foreach (array_slice($ranked, 0, 12) as $v) {
                $pop = (int) round($v->headcount());
                $this->line(sprintf(
                    '    %-18s %5d / %-5d  %s',
                    $v->name, $pop, $v->carryingCapacity,
                    $v->isTracked() ? ($pop === 0 ? 'emptied' : 'tracked') : 'folded into a cohort',
                ));
            }
            if (count($ranked) > 12) {
                $tail = array_slice($ranked, 12);
                $inhabited = count(array_filter($tail, static fn (Village $v): bool => $v->headcount() > 0.0));
                $this->line(sprintf('    … and %d more (%d still inhabited)', count($tail), $inhabited));
            }
            $this->line(sprintf('  (the detail below spotlights the primary settlement, %s)', $world->village->name));
        }
        if ($living !== []) {
            $sickness = array_map(fn (Agent $a) => ($a->needs['sickness'] ?? null)?->value ?? 0.0, $living);
            $ill = count(array_filter($sickness, fn (float $s) => $s >= 40.0));
            $this->line(sprintf(
                '  health: avg sickness %.0f/100  ·  %d gravely ill (crowding, famine, frailty, plague)',
                array_sum($sickness) / count($sickness), $ill,
            ));
            $this->line(sprintf(
                '  mutual aid %.0f%% (generosity shares a famine\'s shortfall → fewer of the vulnerable lost)',
                $world->village->mutualAid * 100,
            ));
        }
        $this->newLine();

        $this->comment('Milestones (story director):');
        foreach ($world->milestones as $m) {
            $pin = $m->hard ? 'hard pin' : 'soft beat';
            if ($m->achieved) {
                $achievedDate = TharadiCalendar::fromTick((int) $m->achievedTick);
                $how = $m->wasForced ? '[forced — the pin held against the world\'s grain]' : '[emerged organically]';
                $this->line(sprintf('  ✓ %s — Year %d %s (%s, budget by Year %d)', ucfirst($m->name), $achievedDate->year, $how, $pin, $m->deadlineYear));
            } elseif ($m->lapsed) {
                $this->line(sprintf('  ⋯ %s — lapsed; the world went another way (%s, budget by Year %d)', ucfirst($m->name), $pin, $m->deadlineYear));
            } else {
                $this->line(sprintf('  ✗ %s — unfulfilled (%s, budget by Year %d)', ucfirst($m->name), $pin, $m->deadlineYear));
            }
        }
        $conflicts = StoryDirector::conflicts($world);
        if ($conflicts !== []) {
            $this->line(sprintf(
                '  ⚠ author\'s hand: %d hard pin(s) forced against emergence — %s',
                count($conflicts),
                implode(', ', array_map(fn ($m) => $m->name, $conflicts)),
            ));
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
            '    └ generated from materials (structural scarcity %.2f · volatility %.2f), then drifts with material security',
            $world->region->scarcity(), $world->region->seasonalVolatility(),
        ));
        $faith = $village->faith();
        $this->line(sprintf(
            '  faith: %s — tenets %s (loyalty %d · authority %d · sanctity %d · care %d · fairness %d · liberty %d), binds at %.2f',
            $faith->name, implode(' & ', $faith->tenets()),
            (int) $faith->loyalty, (int) $faith->authority, (int) $faith->sanctity,
            (int) $faith->care, (int) $faith->fairness, (int) $faith->liberty, $faith->binding,
        ));
        if ($living !== []) {
            $adherence = array_map(fn ($a) => $faith->adherenceOf($a), $living);
            $this->line(sprintf(
                '    └ lived adherence ranges %.2f–%.2f (the devout vs the nominal believer)',
                min($adherence), max($adherence),
            ));
        }
        $this->line(sprintf(
            '  baseline %.2f (from collectivism) decays with scale → %.2f at %d souls (floor %.2f, group size %d)',
            $village->baselineCohesion, $cohesion, count($living), $village->cohesionFloor, $village->cohesiveGroupSize,
        ));
        if ($village->institution !== null) {
            $inst = $village->institution;
            $this->line(sprintf(
                '  institution: %s (%s), founded Year %d — mandate %d%%, effectiveness %d%% (ossifying)',
                $inst->name, $inst->type, TharadiCalendar::fromTick($inst->foundedTick)->year,
                (int) round($inst->mandate * 100), (int) round($inst->effectiveness * 100),
            ));
        } else {
            $this->line('  institution: none — organic cohesion still suffices');
        }
        $this->line('  participation = want-to (cohesion × sociability) + faith + forced-to (institution) + paid-to (treasury):');
        $adults = array_slice(
            array_values(array_filter($living, fn (Agent $a) => $a->ageInYears($world->tick) >= 16)),
            0,
            6,
        );
        foreach ($adults as $a) {
            $this->line(sprintf(
                '    %-9s soc %2.0f → %.2f effort/day',
                $a->name, $a->trait('sociability'), ProjectEngine::participationWeight($a, $cohesion, $village->institution, 0.0, $village->faith()),
            ));
        }
        $this->newLine();

        $this->comment('Economy — granary & carrying capacity:');
        $granary = $village->stockpile;
        $landNote = $village->landYield < $village->baseLandYield - 0.05
            ? sprintf(' (exhausted from %s by overuse)', number_format($village->baseLandYield))
            : '';
        $this->line(sprintf(
            '  land yield %s%s × tech %.1f × avg season %.2f → carrying capacity %d (food ÷ %.0f per head)',
            number_format($village->landYield), $landNote, $village->technology,
            EconomyEngine::averageYieldMultiplier($world->region), $village->carryingCapacity, EconomyEngine::FOOD_PER_CAPITA,
        ));
        $this->line(sprintf(
            '  granary: food %s · water %s · treasury %s money',
            number_format($granary->amount('food')), number_format($granary->amount('water')), number_format($granary->amount('money')),
        ));
        $this->line(sprintf(
            '  this year\'s harvest %.0f%% (ordinary good/lean swing; the granary & mutual aid buffer it, apart from rare shocks)',
            $village->harvestQuality * 100,
        ));
        $this->line(sprintf(
            '  larder: grain %s · dates %s · goat meat %s (the real foodstuffs; perishables spoil)',
            number_format($granary->amount('grain')), number_format($granary->amount('dates')), number_format($granary->amount('goat meat')),
        ));
        $this->line('  goods catalog (what the oasis can yield — nutrition · value · perishability):');
        foreach ($world->goods->all() as $good) {
            $this->line(sprintf('    %-10s %2.0f · %2.0f · %2.0f', $good->name, $good->nutrition, $good->value, $good->perishability));
        }
        $this->line('  kitchen (recipe → meal nutrition; synergy makes a balanced meal beat its raw parts):');
        foreach ($world->recipes->all() as $recipe) {
            $this->line(sprintf(
                '    %-24s %s → %.0f',
                $recipe->name, implode(' + ', array_keys($recipe->ingredients)), $recipe->meal($world->goods)->nutrition,
            ));
        }
        $this->line(sprintf(
            '  diet quality %.0f%% (meals cooked from the larder; when the meat spoils the table falls from stew to bare grain)',
            $village->dietQuality * 100,
        ));
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
            'granary' => $world->village->stockpile->all(),
            'goods' => array_map(
                fn ($g) => ['nutrition' => $g->nutrition, 'value' => $g->value, 'perishability' => $g->perishability],
                $world->goods->all(),
            ),
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
            'chronicle' => array_map(fn ($e) => $e->toArray(), $world->chronicle->all()),
            'roster' => array_map(fn (Agent $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'sex' => $a->sex,
                'age' => $a->ageInYears($world->tick),
                'partnerId' => $a->partnerId,
                'parentIds' => $a->parentIds,
                'activity' => $a->activity?->value,
                'traits' => $a->traits,
                'needs' => array_map(fn ($n) => round($n->value, 1), $a->needs),
                'money' => round($a->stockpile->amount('money'), 1),
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

    /**
     * Parse an end-state spec like "empire@500,town@300" into authored waypoints.
     *
     * @return list<Waypoint>
     */
    private static function parseEndState(string $spec): array
    {
        $waypoints = [];
        foreach (explode(',', $spec) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            [$kind, $year] = array_pad(explode('@', $part, 2), 2, '');
            $waypoints[] = new Waypoint(trim($kind), (int) $year);
        }

        return $waypoints;
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
