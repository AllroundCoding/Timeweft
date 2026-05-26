<?php

namespace App\Console\Commands;

use App\Sim\Support\Rng;
use App\Sim\Worldgen\Substrate;
use App\Sim\Worldgen\SubstrateGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;

#[Signature('world:substrate {--seed=vaeris : RNG seed for a reproducible world} {--width=2560 : Grid columns} {--height=1440 : Grid rows} {--plates=70 : Number of tectonic plate seeds} {--cell=1 : Pixels per cell in the PNG} {--out= : PNG output path (default: storage/app/substrate-{seed}.png)}')]
#[Description('Generate a solid-earth substrate (TWT-130) and render it as a colored elevation PNG + an ASCII preview — eyeball worldgen before the full map view (TWT-134).')]
class WorldSubstrate extends Command
{
    /**
     * Elevation colour ramp, sea-level-relative (sea level = 0): ascending by upper bound, the last band
     * catching everything above. Discrete bands rather than a smooth gradient keep coastlines and mountain
     * arcs legible — the tectonic structure this preview exists to confirm.
     *
     * @var list<array{max: float, rgb: array{0: int, 1: int, 2: int}, ascii: string}>
     */
    private const BANDS = [
        ['max' => -0.25, 'rgb' => [0, 25, 35], 'ascii' => ' '],     // very deep
        ['max' => -0.40, 'rgb' => [0, 47, 69], 'ascii' => '!'],    // deep ocean
        ['max' => -0.25, 'rgb' => [0, 75, 122], 'ascii' => '.'],   // ocean
        ['max' => -0.05, 'rgb' => [74, 156, 217], 'ascii' => ','],   // shallow sea
        ['max' => 0.05, 'rgb' => [218, 190, 126], 'ascii' => ':'],  // coast
        ['max' => 0.20, 'rgb' => [96, 123, 60], 'ascii' => '-'],    // lowland
        ['max' => 0.40, 'rgb' => [89, 187, 89], 'ascii' => '+'],   // hills
        ['max' => 0.65, 'rgb' => [57, 101, 57], 'ascii' => '*'],   // highland
        ['max' => 0.95, 'rgb' => [101, 112, 101], 'ascii' => '#'],  // mountain
        ['max' => INF, 'rgb' => [242, 242, 246], 'ascii' => '^'],   // peak / snow
    ];

    public function handle(): int
    {
        $seed = (string) $this->option('seed');
        $width = max(8, (int) $this->option('width'));
        $height = max(8, (int) $this->option('height'));
        $plates = max(2, (int) $this->option('plates'));
        $cell = max(1, (int) $this->option('cell'));

        $out = (string) $this->option('out');
        if ($out === '') {
            $slug = preg_replace('/[^A-Za-z0-9._-]+/', '_', $seed) ?? 'world';
            $out = storage_path('app/'.$slug.'-substrate'.'.png');
        }

        $substrate = SubstrateGenerator::generate(new Rng($seed), $width, $height, $plates);

        $this->renderSummary($substrate, $seed);
        $this->newLine();
        foreach (self::asciiMap($substrate, 100, 34) as $row) {
            $this->line($row);
        }
        $this->newLine();

        self::writePng($substrate, $out, $cell);
        $this->info('PNG → '.$out);

        return self::SUCCESS;
    }

    private function renderSummary(Substrate $substrate, string $seed): void
    {
        $continental = count(array_filter($substrate->plates, static fn ($plate): bool => $plate->continental));
        [$low, $high] = self::elevationRange($substrate);

        $this->info(sprintf('Substrate — seed "%s", %d×%d grid, %d plates', $seed, $substrate->width, $substrate->height, count($substrate->plates)));
        $this->line(sprintf('  plates   %d continental · %d oceanic', $continental, count($substrate->plates) - $continental));
        $this->line(sprintf('  land     %.1f%% above the waterline', $substrate->landFraction() * 100));
        $this->line(sprintf('  relief   %.2f sea floor … %.2f peak  (sea level 0)', $low, $high));
        $this->line(sprintf('  ore      peak concentration %.2f', self::peakMineral($substrate)));
    }

    /**
     * Downsample the elevation field to a terminal-sized ASCII map. Console characters are about twice as
     * tall as they are wide, so the row budget is halved against the column budget to keep the aspect true.
     *
     * @return list<string>
     */
    private static function asciiMap(Substrate $substrate, int $maxColumns, int $maxRows): array
    {
        $columns = min($maxColumns, $substrate->width);
        $rows = min($maxRows, max(1, (int) round($substrate->height * ($columns / $substrate->width) * 0.5)));

        $lines = [];
        for ($row = 0; $row < $rows; $row++) {
            $y = (int) (($row + 0.5) / $rows * $substrate->height);
            $line = '';
            for ($column = 0; $column < $columns; $column++) {
                $x = (int) (($column + 0.5) / $columns * $substrate->width);
                $line .= self::BANDS[self::bandIndex($substrate->elevationAt($x, $y))]['ascii'];
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /** Paint the elevation field as a colored PNG, with each plate seed marked so relief can be read against the tectonics. */
    private static function writePng(Substrate $substrate, string $path, int $cell): void
    {
        File::ensureDirectoryExists(dirname($path));

        $image = imagecreatetruecolor($substrate->width * $cell, $substrate->height * $cell);
        if ($image === false) {
            throw new RuntimeException('Could not allocate the image canvas (is the GD extension enabled?).');
        }

        // Allocate the ramp once, then paint each cell as a filled block.
        $palette = [];
        foreach (self::BANDS as $index => $band) {
            $colour = imagecolorallocate($image, $band['rgb'][0], $band['rgb'][1], $band['rgb'][2]);
            $palette[$index] = $colour === false ? 0 : $colour;
        }

        for ($y = 0; $y < $substrate->height; $y++) {
            for ($x = 0; $x < $substrate->width; $x++) {
                $colour = $palette[self::bandIndex($substrate->elevationAt($x, $y))];
                imagefilledrectangle($image, $x * $cell, $y * $cell, ($x + 1) * $cell - 1, ($y + 1) * $cell - 1, $colour);
            }
        }

        // Plate seeds, so it reads at a glance that mountains rise where plates converge.
        $marker = imagecolorallocate($image, 220, 40, 40);
        $radius = max(2, (int) round($cell * 0.6));
        foreach ($substrate->plates as $plate) {
            imagefilledellipse($image, (int) round($plate->x * $cell), (int) round($plate->y * $cell), $radius * 2, $radius * 2, $marker === false ? 0 : $marker);
        }

        imagepng($image, $path);
        imagedestroy($image);
    }

    /** The ramp band an elevation falls in — the first band whose upper bound it does not exceed. */
    private static function bandIndex(float $elevation): int
    {
        foreach (self::BANDS as $index => $band) {
            if ($elevation <= $band['max']) {
                return $index;
            }
        }

        return count(self::BANDS) - 1;
    }

    /** @return array{0: float, 1: float} lowest and highest elevation on the grid */
    private static function elevationRange(Substrate $substrate): array
    {
        $low = INF;
        $high = -INF;
        for ($y = 0; $y < $substrate->height; $y++) {
            for ($x = 0; $x < $substrate->width; $x++) {
                $value = $substrate->elevationAt($x, $y);
                $low = min($low, $value);
                $high = max($high, $value);
            }
        }

        return [$low, $high];
    }

    private static function peakMineral(Substrate $substrate): float
    {
        $peak = 0.0;
        for ($y = 0; $y < $substrate->height; $y++) {
            for ($x = 0; $x < $substrate->width; $x++) {
                $peak = max($peak, $substrate->mineralAt($x, $y));
            }
        }

        return $peak;
    }
}
