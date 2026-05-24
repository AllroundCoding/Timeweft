<?php

namespace App\Http\Controllers;

use App\Http\Timeline\TimelineProjection;
use App\Narrative\Narrator;
use App\Narrative\Saga;
use App\Sim\Chronicle\ChronicleEvent;
use App\Sim\Engine;
use App\Sim\Time\TharadiCalendar;
use App\Sim\World\Agent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TimelineController extends Controller
{
    /**
     * Render the gantt timeline for a seeded run. The engine stays authoritative: this drives its
     * public API (seed · advance · query) and projects the result — it never reaches into the core.
     * Run parameters come from the query string so any reproducible world is viewable; they default
     * to the canonical run and are clamped so an extreme `?years=` can't hang the request.
     */
    public function __invoke(Request $request, Narrator $narrator): Response
    {
        $seed = (string) $request->string('seed', 'vaeris');
        $years = max(1, min($request->integer('years', 22), 200));
        $population = max(1, min($request->integer('population', 8), 60));

        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;
        $engine = Engine::seed($seed, $population)->advance($years * $ticksPerYear);
        $saga = $this->sagaFor($engine, $seed);

        return Inertia::render('Timeline', [
            'run' => ['seed' => $seed, 'years' => $years, 'population' => $population],
            ...TimelineProjection::from($engine),
            // Deferred: the gantt paints immediately; the (possibly slow, LLM-backed) narration loads
            // in a follow-up request and is served from the app cache on every subsequent view.
            'narrative' => Inertia::defer(fn (): string => $narrator->retell($saga)),
        ]);
    }

    /** Assemble the canonical material the narrator retells, from the engine's query surface. */
    private function sagaFor(Engine $engine, string $seed): Saga
    {
        $agents = $engine->agents();
        $born = count(array_filter($agents, static fn (Agent $a): bool => $a->parentIds !== []));
        $living = count(array_filter($agents, static fn (Agent $a): bool => $a->alive));

        return new Saga(
            world: $engine->world()->village->name,
            region: $engine->world()->village->region,
            seed: $seed,
            startYear: TharadiCalendar::fromTick(0)->year,
            endYear: TharadiCalendar::fromTick($engine->tick())->year,
            events: array_map(static fn (ChronicleEvent $e): array => [
                'year' => TharadiCalendar::fromTick($e->tick)->year,
                'text' => $e->text,
                'type' => $e->type,
            ], $engine->chronicle()),
            population: [
                'founders' => count($agents) - $born,
                'born' => $born,
                'died' => count($agents) - $living,
                'living' => $living,
            ],
        );
    }
}
