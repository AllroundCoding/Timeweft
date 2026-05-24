<?php

namespace App\Http\Controllers;

use App\Http\Timeline\TimelineProjection;
use App\Sim\Engine;
use App\Sim\Time\TharadiCalendar;
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
    public function __invoke(Request $request): Response
    {
        $seed = (string) $request->string('seed', 'vaeris');
        $years = max(1, min($request->integer('years', 22), 200));
        $population = max(1, min($request->integer('population', 8), 60));

        $ticksPerYear = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;
        $engine = Engine::seed($seed, $population)->advance($years * $ticksPerYear);

        return Inertia::render('Timeline', [
            'run' => ['seed' => $seed, 'years' => $years, 'population' => $population],
            ...TimelineProjection::from($engine),
        ]);
    }
}
