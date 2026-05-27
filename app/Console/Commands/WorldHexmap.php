<?php

namespace App\Console\Commands;

use App\Sim\Hex\Hex;
use App\Sim\Hex\HexCoord;
use App\Sim\Hex\HexMapProjector;
use App\Sim\Support\Rng;
use App\Sim\Worldgen\Biome;
use App\Sim\Worldgen\CirculationGenerator;
use App\Sim\Worldgen\ClimateGenerator;
use App\Sim\Worldgen\HydrologyGenerator;
use App\Sim\Worldgen\SubstrateGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('world:hexmap {--cols=48 : Hex columns} {--rows=24 : Hex rows} {--width=200 : Raster width} {--height=120 : Raster height} {--plates=16 : Tectonic plates} {--seed=vaeris : RNG seed}')]
#[Description('Project the procedural worldgen onto a playable hex grid and preview it (TWT-275)')]
class WorldHexmap extends Command
{
    public function handle(): int
    {
        $seed = (string) $this->option('seed');
        $rng = new Rng($seed);
        $width = (int) $this->option('width');
        $height = (int) $this->option('height');
        $plates = (int) $this->option('plates');
        $cols = (int) $this->option('cols');
        $rows = (int) $this->option('rows');

        $substrate = SubstrateGenerator::generate($rng, $width, $height, $plates);
        $circulation = CirculationGenerator::generate($rng, $substrate);
        $climate = ClimateGenerator::generate($rng, $substrate, $circulation);
        $hydrology = HydrologyGenerator::generate($substrate, $climate);
        $grid = HexMapProjector::project($substrate, $climate, $hydrology, $cols, $rows);

        $this->info(sprintf('Hex map — %d×%d hexes over a %d×%d world, seed "%s"', $cols, $rows, $width, $height, $seed));
        $this->newLine();

        for ($r = 0; $r < $rows; $r++) {
            $line = $r % 2 === 1 ? ' ' : ''; // stagger odd rows by half a hex
            for ($q = 0; $q < $cols; $q++) {
                $line .= self::glyph($grid->at(new HexCoord($q, $r))).' ';
            }
            $this->line($line);
        }

        $this->newLine();
        $this->line('Legend: ~ sea   o lake   = river   * ice   . tundra   : desert   ; shrub   n grass   T forest   # rainforest');

        return self::SUCCESS;
    }

    private static function glyph(?Hex $hex): string
    {
        if ($hex === null) {
            return ' ';
        }
        if (! $hex->isLand) {
            return '~';
        }
        if ($hex->isLake) {
            return 'o';
        }
        if ($hex->isRiver) {
            return '=';
        }

        return match ($hex->biome) {
            Biome::Ocean => '~',
            Biome::Ice => '*',
            Biome::Tundra => '.',
            Biome::Desert => ':',
            Biome::Shrubland => ';',
            Biome::Grassland => 'n',
            Biome::Forest => 'T',
            Biome::Rainforest => '#',
        };
    }
}
