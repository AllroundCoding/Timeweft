<?php

namespace App\Http\Controllers;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\Biome;
use App\Sim\Worldgen\CirculationGenerator;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\Hydrology;
use App\Sim\Worldgen\HydrologyGenerator;
use App\Sim\Worldgen\SettlementSite;
use App\Sim\Worldgen\SettlementSiter;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The play surface (design doc 23; TWT-285). The world is one continuous terrain at the overview scale —
 * not a hex grid; hexes are the management-zoom layer (TWT-275, a resolution down) and per-settlement
 * detail is a layer below that. This serves the continuous biome/terrain raster (one char per cell) plus
 * sited settlements for the React view to render and pan/zoom.
 *
 * A read-only projection — it generates from a seed and reads, never touching the canonical sim, so the
 * seeded run is unaffected. Sizes are clamped so an extreme request can't hang.
 */
class MapController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $seed = (string) $request->string('seed', 'vaeris');
        $width = max(40, min($request->integer('width', 200), 400));
        $height = max(30, min($request->integer('height', 120), 240));
        $plates = max(4, min($request->integer('plates', 16), 90));

        $rng = new Rng($seed);
        $substrate = SubstrateGenerator::generate($rng, $width, $height, $plates);
        $circulation = CirculationGenerator::generate($rng, $substrate);
        $climate = ClimateGenerator::generate($rng, $substrate, $circulation);
        $hydrology = HydrologyGenerator::generate($substrate, $climate);

        // One char per cell — a compact terrain raster the canvas paints (16-bit-light over JSON objects).
        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            $line = '';
            for ($x = 0; $x < $width; $x++) {
                $line .= self::cell($substrate, $climate, $hydrology, $x, $y);
            }
            $rows[] = $line;
        }

        return Inertia::render('Map', [
            'run' => ['seed' => $seed, 'width' => $width, 'height' => $height],
            'width' => $width,
            'height' => $height,
            'rows' => $rows,
            'settlements' => array_map(static fn (SettlementSite $s): array => [
                'nx' => round($s->x / max(1, $width), 4),
                'ny' => round($s->y / max(1, $height), 4),
                'tier' => $s->tier->value,
            ], array_slice(SettlementSiter::site($substrate, $climate, $hydrology), 0, 60)),
        ]);
    }

    /** A single terrain glyph for a cell: water first, then the biome. */
    private static function cell(Substrate $substrate, Climate $climate, Hydrology $hydrology, int $x, int $y): string
    {
        if (! $substrate->isLand($x, $y)) {
            return 'O';
        }
        if ($hydrology->isRiver($x, $y)) {
            return '~';
        }
        if ($hydrology->isLake($x, $y)) {
            return 'L';
        }

        return match ($climate->biomeAt($x, $y)) {
            Biome::Ocean => 'O',
            Biome::Ice => 'I',
            Biome::Tundra => 'T',
            Biome::Desert => 'D',
            Biome::Shrubland => 'S',
            Biome::Grassland => 'G',
            Biome::Forest => 'F',
            Biome::Rainforest => 'J',
        };
    }
}
