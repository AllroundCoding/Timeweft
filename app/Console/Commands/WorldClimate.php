<?php

namespace App\Console\Commands;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\Biome;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\CirculationGenerator; // <-- ADDED
use App\Sim\Worldgen\Hydrology;
use App\Sim\Worldgen\HydrologyGenerator;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('world:climate {--seed=vaeris : RNG seed for a reproducible world} {--width=320 : Grid columns} {--height=160 : Grid rows} {--plates=18 : Number of tectonic plate seeds} {--cell=6 : Pixels per cell in the PNG} {--layer=biome : Which layer to paint — biome|temperature|precipitation|fertility} {--hide-water : Hide the rivers & lakes overlay} {--out= : PNG output path (default: storage/app/climate-SEED-LAYER.png)}')]
#[Description('Derive the climate (TWT-132) + hydrology (TWT-131) from a generated substrate and render a layer — biome, temperature, rainfall, or fertility — with rivers & lakes, as a PNG + an ASCII biome map.')]
class WorldClimate extends Command
{
    private const LAYERS = ['biome', 'temperature', 'precipitation', 'fertility'];

    private const GLYPHS = [
        'ocean' => ' ', 'ice' => '*', 'tundra' => '.', 'desert' => ':',
        'shrubland' => ';', 'grassland' => '-', 'forest' => '#', 'rainforest' => '@',
    ];

    public function handle(): int
    {
        $layer = strtolower((string) $this->option('layer'));
        if (! in_array($layer, self::LAYERS, true)) {
            $this->error('Unknown --layer "'.$layer.'". Choose one of: '.implode(', ', self::LAYERS).'.');
            return self::FAILURE;
        }

        $seed = (string) $this->option('seed');
        $width = max(8, (int) $this->option('width'));
        $height = max(8, (int) $this->option('height'));
        $plates = max(2, (int) $this->option('plates'));
        $cell = max(1, (int) $this->option('cell'));

        $out = (string) $this->option('out');
        if ($out === '') {
            $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $seed) ?? 'world';
            $out = storage_path('app/climate-'.$slug.'-'.$layer.'.png');
        }

        $substrate = SubstrateGenerator::generate(new Rng($seed), $width, $height, $plates);

        // --- NEW CIRCULATION GENERATION STEP ---
        $circulation = CirculationGenerator::generate(new Rng($seed), $substrate);

        // --- PASS IT TO THE CLIMATE GENERATOR ---
        $climate = ClimateGenerator::generate($substrate, $circulation);

        $hydrology = $this->option('hide-water') ? null : HydrologyGenerator::generate($substrate, $climate);

        $this->renderSummary($climate, $substrate, $hydrology, $seed, $layer);
        $this->newLine();
        foreach (self::asciiBiomeMap($climate, 100, 34) as $row) {
            $this->line($row);
        }
        $this->newLine();

        self::writePng($climate, $substrate, $hydrology, $layer, $out, $cell);
        $this->info('PNG → '.$out);

        return self::SUCCESS;
    }

    private function renderSummary(Climate $climate, Substrate $substrate, ?Hydrology $hydrology, string $seed, string $layer): void
    {
        $landCells = 0; $fertile = 0; $rivers = 0; $lakes = 0; $temperatureSum = 0.0; $counts = [];
        for ($y = 0; $y < $climate->height; $y++) {
            for ($x = 0; $x < $climate->width; $x++) {
                $temperatureSum += $climate->temperatureAt($x, $y);
                $biome = $climate->biomeAt($x, $y);
                $counts[$biome->value] = ($counts[$biome->value] ?? 0) + 1;
                if ($substrate->isLand($x, $y)) {
                    $landCells++;
                    if ($climate->fertilityAt($x, $y) > 0.25) $fertile++;
                }
                if ($hydrology !== null && $hydrology->isRiver($x, $y)) $rivers++;
                if ($hydrology !== null && $hydrology->isLake($x, $y)) $lakes++;
            }
        }
        arsort($counts);
        $cells = $climate->width * $climate->height;

        $this->info(sprintf('Climate — seed "%s", %d×%d grid · painting %s', $seed, $climate->width, $climate->height, $layer));
        $this->line(sprintf('  mean temp   %.1f°C', $temperatureSum / max(1, $cells)));
        $this->line(sprintf('  arable land %.1f%% of land is good farmland', $landCells > 0 ? $fertile / $landCells * 100 : 0.0));
        $this->line('  biomes      '.implode(' · ', self::topBiomes($counts, $cells)));
        if ($hydrology !== null) $this->line(sprintf('  water       %d river cells · %d lake cells', $rivers, $lakes));
    }

    private static function topBiomes(array $counts, int $cells): array
    {
        $parts = [];
        foreach (array_slice($counts, 0, 5, true) as $name => $count) {
            $parts[] = sprintf('%s %.0f%%', $name, $count / max(1, $cells) * 100);
        }
        return $parts;
    }

    private static function asciiBiomeMap(Climate $climate, int $maxColumns, int $maxRows): array
    {
        $columns = min($maxColumns, $climate->width);
        $rows = min($maxRows, max(1, (int) round($climate->height * ($columns / $climate->width) * 0.5)));

        $lines = [];
        for ($row = 0; $row < $rows; $row++) {
            $y = (int) (($row + 0.5) / $rows * $climate->height);
            $line = '';
            for ($column = 0; $column < $columns; $column++) {
                $x = (int) (($column + 0.5) / $columns * $climate->width);
                $line .= self::GLYPHS[$climate->biomeAt($x, $y)->value];
            }
            $lines[] = $line;
        }
        return $lines;
    }

    private static function writePng(Climate $climate, Substrate $substrate, ?Hydrology $hydrology, string $layer, string $path, int $cell): void
    {
        File::ensureDirectoryExists(dirname($path));

        $image = imagecreatetruecolor($climate->width * $cell, $climate->height * $cell);
        if ($image === false) throw new RuntimeException('Could not allocate the image canvas.');

        for ($y = 0; $y < $climate->height; $y++) {
            for ($x = 0; $x < $climate->width; $x++) {
                [$r, $g, $b] = self::colorFor($layer, $climate, $substrate, $hydrology, $x, $y);
                $colour = imagecolorallocate($image, $r, $g, $b);
                imagefilledrectangle($image, $x * $cell, $y * $cell, ($x + 1) * $cell - 1, ($y + 1) * $cell - 1, $colour === false ? 0 : $colour);
            }
        }
        imagepng($image, $path);
        imagedestroy($image);
    }

    private static function colorFor(string $layer, Climate $climate, Substrate $substrate, ?Hydrology $hydrology, int $x, int $y): array
    {
        if ($hydrology !== null && $hydrology->isLake($x, $y)) return [36, 78, 148];
        if ($hydrology !== null && $hydrology->isRiver($x, $y)) return [54, 120, 210];

        $sea = [22, 42, 78];
        $land = $substrate->isLand($x, $y);

        return match ($layer) {
            'temperature' => self::gradient(self::TEMPERATURE_STOPS, self::clamp(($climate->temperatureAt($x, $y) + 25.0) / 60.0, 0.0, 1.0)),
            'precipitation' => $land ? self::gradient(self::PRECIPITATION_STOPS, $climate->precipitationAt($x, $y)) : $sea,
            'fertility' => $land ? self::gradient(self::FERTILITY_STOPS, $climate->fertilityAt($x, $y)) : $sea,
            default => self::biomeColor($climate->biomeAt($x, $y)),
        };
    }

    private static function biomeColor(Biome $biome): array
    {
        return match ($biome) {
            Biome::Ocean => [40, 90, 160],
            Biome::Ice => [236, 240, 245],
            Biome::Tundra => [150, 162, 150],
            Biome::Desert => [222, 202, 132],
            Biome::Shrubland => [172, 168, 92],
            Biome::Grassland => [122, 182, 82],
            Biome::Forest => [42, 120, 52],
            Biome::Rainforest => [20, 82, 36],
        };
    }

    private const TEMPERATURE_STOPS = [[0.0, [30, 60, 140]], [0.4, [120, 180, 220]], [0.6, [236, 232, 170]], [0.8, [220, 130, 50]], [1.0, [170, 32, 32]]];
    private const PRECIPITATION_STOPS = [[0.0, [214, 196, 128]], [1.0, [30, 100, 170]]];
    private const FERTILITY_STOPS = [[0.0, [210, 200, 162]], [1.0, [28, 122, 44]]];

    private static function gradient(array $stops, float $value): array
    {
        $value = self::clamp($value, 0.0, 1.0);
        for ($i = 1; $i < count($stops); $i++) {
            if ($value <= $stops[$i][0]) {
                [$p0, $c0] = $stops[$i - 1];
                [$p1, $c1] = $stops[$i];
                $span = $p1 - $p0;
                $f = $span > 0.0 ? ($value - $p0) / $span : 0.0;
                return [self::lerp($c0[0], $c1[0], $f), self::lerp($c0[1], $c1[1], $f), self::lerp($c0[2], $c1[2], $f)];
            }
        }
        return $stops[count($stops) - 1][1];
    }

    private static function lerp(int $from, int $to, float $f): int { return (int) round($from + ($to - $from) * $f); }
    private static function clamp(float $value, float $low, float $high): float { return max($low, min($high, $value)); }
}
