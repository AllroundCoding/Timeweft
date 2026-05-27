<?php

namespace App\Http\Controllers;

use App\Sim\Hex\Hex;
use App\Sim\Hex\HexCoord;
use App\Sim\Hex\HexMapProjector;
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
 * The play surface (design doc 16/23; TWT-285). The world is one continuous terrain at the overview scale
 * — not a hex grid; hexes are the management-zoom layer (the {@see HexMapProjector} play projection, a
 * resolution down: 1 hex = 1 resource tile) and per-settlement detail is a layer below that. This serves
 * both the continuous biome raster (one char per cell) and the coarse hex grid (one char per hex) so the
 * React view can crossfade between them as the camera zooms.
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

        // One char per cell — the compact terrain raster the canvas paints (lighter than JSON objects).
        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            $line = '';
            for ($x = 0; $x < $width; $x++) {
                $line .= self::biomeGlyph($substrate, $climate, $hydrology, $x, $y);
            }
            $rows[] = $line;
        }

        return Inertia::render('Map', [
            'run' => ['seed' => $seed, 'width' => $width, 'height' => $height],
            'width' => $width,
            'height' => $height,
            'rows' => $rows,
            'hex' => self::hexGrid($substrate, $climate, $hydrology, $width, $height),
            'settlements' => array_map(static fn (SettlementSite $s): array => [
                'nx' => round($s->x / max(1, $width), 4),
                'ny' => round($s->y / max(1, $height), 4),
                'tier' => $s->tier->value,
            ], array_slice(SettlementSiter::site($substrate, $climate, $hydrology), 0, 60)),
        ]);
    }

    /**
     * The management-zoom grid: a coarse hex projection (≈ 1 hex per 3×3 cells) as glyph rows, mirroring
     * the terrain raster's encoding so the view shares one palette.
     *
     * @return array{cols:int,rows:int,cells:list<string>}
     */
    private static function hexGrid(Substrate $substrate, Climate $climate, Hydrology $hydrology, int $width, int $height): array
    {
        $cols = max(8, min((int) round($width / 3), 90));
        $rows = max(6, min((int) round($height / 3), 60));
        $grid = HexMapProjector::project($substrate, $climate, $hydrology, $cols, $rows);

        $cells = [];
        for ($r = 0; $r < $rows; $r++) {
            $line = '';
            for ($q = 0; $q < $cols; $q++) {
                $hex = $grid->at(new HexCoord($q, $r));
                $line .= $hex !== null ? self::hexGlyph($hex) : 'O';
            }
            $cells[] = $line;
        }

        return ['cols' => $cols, 'rows' => $rows, 'cells' => $cells];
    }

    /** A single terrain glyph for a raster cell: water first, then the biome. */
    private static function biomeGlyph(Substrate $substrate, Climate $climate, Hydrology $hydrology, int $x, int $y): string
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

        return self::glyphFor($climate->biomeAt($x, $y));
    }

    /** The same glyph rule for a projected hex, read off its sampled fields. */
    private static function hexGlyph(Hex $hex): string
    {
        if (! $hex->isLand) {
            return 'O';
        }
        if ($hex->isRiver) {
            return '~';
        }
        if ($hex->isLake) {
            return 'L';
        }

        return self::glyphFor($hex->biome);
    }

    private static function glyphFor(Biome $biome): string
    {
        return match ($biome) {
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
