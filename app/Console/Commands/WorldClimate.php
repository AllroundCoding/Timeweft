<?php

namespace App\Console\Commands;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\Biome;
use App\Sim\Worldgen\CirculationGenerator;
use App\Sim\Worldgen\Climate;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\Hydrology;
use App\Sim\Worldgen\HydrologyGenerator;
use App\Sim\Worldgen\SettlementSite;
use App\Sim\Worldgen\SettlementSiter;
use App\Sim\Worldgen\SettlementTier;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('world:climate {--seed=vaeris : RNG seed for a reproducible world} {--width=3840 : Grid columns} {--height=2160 : Grid rows} {--plates=90 : Number of tectonic plate seeds} {--cell=1 : Pixels per cell in the PNG} {--layer=biome : Which layer to paint — biome|temperature|precipitation|fertility} {--hide-water : Hide the rivers & lakes overlay} {--sites : Mark emergent settlements (TWT-82)} {--out= : PNG output path (default: storage/app/climate-SEED-LAYER.png)}')]
#[Description('Derive the climate (TWT-132) + hydrology (TWT-131) from a generated substrate and render a layer — biome, temperature, rainfall, or fertility — with rivers & lakes, as a PNG + an ASCII biome map.')]
class WorldClimate extends Command
{
    private const array LAYERS = ['biome', 'temperature', 'precipitation', 'fertility'];

    private const array GLYPHS = [
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
            $out = storage_path('app/'.$slug.'-climate-'.$layer.'.png');
        }

        $rng = new Rng($seed);
        $substrate = SubstrateGenerator::generate($rng, $width, $height, $plates);
        $circulation = CirculationGenerator::generate($rng, $substrate);
        $climate = ClimateGenerator::generate($rng, $substrate, $circulation);

        $withSites = (bool) $this->option('sites');
        $hydrology = ($this->option('hide-water') && ! $withSites) ? null : HydrologyGenerator::generate($substrate, $climate);
        $sites = $withSites && $hydrology !== null ? SettlementSiter::site($substrate, $climate, $hydrology) : [];

        $this->renderSummary($climate, $substrate, $hydrology, $sites, $seed, $layer);
        $this->newLine();
        foreach (self::asciiBiomeMap($climate, 100, 34) as $row) {
            $this->line($row);
        }
        $this->newLine();

        self::writePng($climate, $substrate, $hydrology, $sites, $layer, $out, $cell);
        $this->info('PNG → '.$out);

        return self::SUCCESS;
    }

    /** @param  list<SettlementSite>  $sites */
    private function renderSummary(Climate $climate, Substrate $substrate, ?Hydrology $hydrology, array $sites, string $seed, string $layer): void
    {
        $landCells = 0;
        $fertile = 0;
        $rivers = 0;
        $lakes = 0;
        $temperatureSum = 0.0;
        $counts = [];
        for ($y = 0; $y < $climate->height; $y++) {
            for ($x = 0; $x < $climate->width; $x++) {
                $temperatureSum += $climate->temperatureAt($x, $y);
                $biome = $climate->biomeAt($x, $y);
                $counts[$biome->value] = ($counts[$biome->value] ?? 0) + 1;
                if ($substrate->isLand($x, $y)) {
                    $landCells++;
                    if ($climate->fertilityAt($x, $y) > 0.25) {
                        $fertile++;
                    }
                }
                if ($hydrology !== null && $hydrology->isRiver($x, $y)) {
                    $rivers++;
                }
                if ($hydrology !== null && $hydrology->isLake($x, $y)) {
                    $lakes++;
                }
            }
        }
        arsort($counts);
        $cells = $climate->width * $climate->height;

        $this->info(sprintf('Climate — seed "%s", %d×%d grid · painting %s', $seed, $climate->width, $climate->height, $layer));
        $this->line(sprintf('  mean temp   %.1f°C', $temperatureSum / max(1, $cells)));
        $this->line(sprintf('  arable land %.1f%% of land is good farmland', $landCells > 0 ? $fertile / $landCells * 100 : 0.0));
        $this->line('  biomes      '.implode(' · ', self::topBiomes($counts, $cells)));
        if ($hydrology !== null) {
            $this->line(sprintf('  water       %d river cells · %d lake cells', $rivers, $lakes));
        }
        if ($sites !== []) {
            $tiers = [];
            foreach ($sites as $site) {
                $tiers[$site->tier->value] = ($tiers[$site->tier->value] ?? 0) + 1;
            }
            $this->line(sprintf('  settlements %d sited · %s', count($sites), self::tierBreakdown($tiers)));
        }
    }

    /**
     * @param  array<string, int>  $tiers
     */
    private static function tierBreakdown(array $tiers): string
    {
        $parts = [];
        foreach ([SettlementTier::City, SettlementTier::Town, SettlementTier::Village, SettlementTier::Hamlet] as $tier) {
            if (($tiers[$tier->value] ?? 0) > 0) {
                $parts[] = $tiers[$tier->value].' '.$tier->value;
            }
        }

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private static function topBiomes(array $counts, int $cells): array
    {
        $parts = [];
        foreach (array_slice($counts, 0, 5, true) as $name => $count) {
            $parts[] = sprintf('%s %.0f%%', $name, $count / max(1, $cells) * 100);
        }

        return $parts;
    }

    /** @return list<string> */
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

    /** @param  list<SettlementSite>  $sites */
    private static function writePng(Climate $climate, Substrate $substrate, ?Hydrology $hydrology, array $sites, string $layer, string $path, int $cell): void
    {
        File::ensureDirectoryExists(dirname($path));

        $image = imagecreatetruecolor($climate->width * $cell, $climate->height * $cell);
        if ($image === false) {
            throw new RuntimeException('Could not allocate the image canvas.');
        }

        for ($y = 0; $y < $climate->height; $y++) {
            for ($x = 0; $x < $climate->width; $x++) {
                [$r, $g, $b] = self::colorFor($layer, $climate, $substrate, $hydrology, $x, $y);
                $colour = imagecolorallocate($image, $r, $g, $b);
                imagefilledrectangle($image, $x * $cell, $y * $cell, ($x + 1) * $cell - 1, ($y + 1) * $cell - 1, $colour === false ? 0 : $colour);
            }
        }

        if ($sites !== []) {
            $ring = imagecolorallocate($image, 20, 20, 20);
            $dot = imagecolorallocate($image, 255, 232, 120);
            foreach ($sites as $site) {
                $cx = (int) ($site->x * $cell + $cell / 2);
                $cy = (int) ($site->y * $cell + $cell / 2);
                $diameter = self::markerDiameter($site->tier);
                imagefilledellipse($image, $cx, $cy, $diameter + 2, $diameter + 2, $ring === false ? 0 : $ring);
                imagefilledellipse($image, $cx, $cy, $diameter, $diameter, $dot === false ? 0 : $dot);
            }
        }

        imagepng($image, $path);
        imagedestroy($image);
    }

    /** Marker diameter in pixels by tier — bigger settlements draw bigger dots. */
    private static function markerDiameter(SettlementTier $tier): int
    {
        return match ($tier) {
            SettlementTier::City => 12,
            SettlementTier::Town => 8,
            SettlementTier::Village => 5,
            SettlementTier::Hamlet => 3,
        };
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function colorFor(string $layer, Climate $climate, Substrate $substrate, ?Hydrology $hydrology, int $x, int $y): array
    {
        if ($hydrology !== null && $hydrology->isLake($x, $y)) {
            return [36, 78, 148];
        }
        if ($hydrology !== null && $hydrology->isRiver($x, $y)) {
            return [54, 120, 210];
        }

        $sea = [22, 42, 78];
        $land = $substrate->isLand($x, $y);

        return match ($layer) {
            'temperature' => self::gradient(self::TEMPERATURE_STOPS, self::clamp(($climate->temperatureAt($x, $y) + 25.0) / 60.0, 0.0, 1.0)),
            'precipitation' => $land ? self::gradient(self::PRECIPITATION_STOPS, $climate->precipitationAt($x, $y)) : $sea,
            'fertility' => $land ? self::gradient(self::FERTILITY_STOPS, $climate->fertilityAt($x, $y)) : $sea,
            default => self::biomeColor($climate->biomeAt($x, $y)),
        };
    }

    /** @return array{0: int, 1: int, 2: int} */
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

    /**
     * @param  list<array{0: float, 1: array{0: int, 1: int, 2: int}}>  $stops
     * @return array{0: int, 1: int, 2: int}
     */
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

    private static function lerp(int $from, int $to, float $f): int
    {
        return (int) round($from + ($to - $from) * $f);
    }

    private static function clamp(float $value, float $low, float $high): float
    {
        return max($low, min($high, $value));
    }
}
