<?php

namespace App\Http\Controllers;

use App\Sim\Hex\Hex;
use App\Sim\Hex\HexMapProjector;
use App\Sim\Support\Rng;
use App\Sim\Worldgen\CirculationGenerator;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\HydrologyGenerator;
use App\Sim\Worldgen\SettlementSite;
use App\Sim\Worldgen\SettlementSiter;
use App\Sim\Worldgen\SubstrateGenerator;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The play surface (design doc 23; TWT-285/275): a top-down, pan/zoom view of a generated world. The
 * worldgen produces continuous fields, the {@see HexMapProjector} turns them into the playable hex grid,
 * and this serves both to the React view. A pure projection — it generates the world from a seed and
 * reads it; it never touches the canonical sim, so the seeded run is unaffected. Parameters come from the
 * query string and are clamped so an extreme size can't hang the request.
 */
class MapController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $seed = (string) $request->string('seed', 'vaeris');
        $width = max(40, min($request->integer('width', 160), 400));
        $height = max(30, min($request->integer('height', 100), 240));
        $plates = max(4, min($request->integer('plates', 16), 90));
        $cols = max(8, min($request->integer('cols', 64), 160));
        $rows = max(6, min($request->integer('rows', 40), 100));

        $rng = new Rng($seed);
        $substrate = SubstrateGenerator::generate($rng, $width, $height, $plates);
        $circulation = CirculationGenerator::generate($rng, $substrate);
        $climate = ClimateGenerator::generate($rng, $substrate, $circulation);
        $hydrology = HydrologyGenerator::generate($substrate, $climate);
        $grid = HexMapProjector::project($substrate, $climate, $hydrology, $cols, $rows);

        return Inertia::render('Map', [
            'run' => ['seed' => $seed, 'cols' => $cols, 'rows' => $rows],
            'hexes' => array_map(static fn (Hex $h): array => [
                'q' => $h->coord->q,
                'r' => $h->coord->r,
                'biome' => $h->biome->value,
                'land' => $h->isLand,
                'river' => $h->isRiver,
                'lake' => $h->isLake,
                'elevation' => round($h->elevation, 2),
            ], $grid->hexes),
            'settlements' => array_map(
                fn (SettlementSite $s): array => [
                    ...$this->siteToHex($s, $width, $height, $cols, $rows),
                    'tier' => $s->tier->value,
                ],
                array_slice(SettlementSiter::site($substrate, $climate, $hydrology), 0, 40),
            ),
        ]);
    }

    /**
     * Map a sited settlement's raster position onto the hex lattice (the inverse of the projector's
     * sampling), so its marker lands on the hex it belongs to.
     *
     * @return array{q:int,r:int}
     */
    private function siteToHex(SettlementSite $site, int $width, int $height, int $cols, int $rows): array
    {
        return [
            'q' => $cols > 1 ? (int) round($site->x / max(1, $width - 1) * ($cols - 1)) : 0,
            'r' => $rows > 1 ? (int) round($site->y / max(1, $height - 1) * ($rows - 1)) : 0,
        ];
    }
}
