<?php

namespace Tests\Unit\Sim;

use App\Sim\Worldgen\FractalNoise;
use App\Sim\Worldgen\HydraulicErosion;
use PHPUnit\Framework\TestCase;

/**
 * TWT-262 pass 2 — fluvial erosion. It drains the closed basins that fractal relief leaves behind and
 * cuts river channels into the land. Deterministic: the same heightmap erodes to the same valleys.
 */
class HydraulicErosionTest extends TestCase
{
    private const W = 40;

    private const H = 30;

    public function test_it_is_deterministic(): void
    {
        $dem = $this->bumpyTerrain();

        $this->assertSame(
            HydraulicErosion::erode($dem, self::W, self::H),
            HydraulicErosion::erode($dem, self::W, self::H),
            'same terrain → the same erosion',
        );
    }

    public function test_it_drains_the_closed_basins(): void
    {
        $dem = $this->bumpyTerrain();
        $before = $this->countSinks($dem);
        $after = $this->countSinks(HydraulicErosion::erode($dem, self::W, self::H));

        $this->assertGreaterThan(0, $before, 'the raw fractal terrain has closed basins');
        $this->assertLessThan($before, $after, 'erosion fills them so water reaches the sea');
    }

    public function test_it_cuts_channels_into_the_land(): void
    {
        $dem = $this->bumpyTerrain();
        $eroded = HydraulicErosion::erode($dem, self::W, self::H);

        $cut = false;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                if ($eroded[$y][$x] < $dem[$y][$x] - 0.01) {
                    $cut = true;
                }
            }
        }

        $this->assertTrue($cut, 'rivers cut the land down where they run');
    }

    /** A bumpy, mostly-land heightmap (with closed basins) ringed by sea, so there is an outlet. @return list<list<float>> */
    private function bumpyTerrain(): array
    {
        $noise = new FractalNoise(99, 0.08);
        $dem = [];
        for ($y = 0; $y < self::H; $y++) {
            $row = [];
            for ($x = 0; $x < self::W; $x++) {
                $edge = $x < 2 || $y < 2 || $x >= self::W - 2 || $y >= self::H - 2;
                $row[] = $edge ? -0.5 : 0.25 + 0.35 * $noise->fbmSpherical((float) $x, (float) $y, (float) self::W, (float) self::H);
            }
            $dem[] = $row;
        }

        return $dem;
    }

    /** @param  list<list<float>>  $dem */
    private function countSinks(array $dem): int
    {
        $sinks = 0;
        for ($y = 1; $y < self::H - 1; $y++) {
            for ($x = 1; $x < self::W - 1; $x++) {
                if ($dem[$y][$x] <= 0.0) {
                    continue;
                }
                $isSink = true;
                foreach ([[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]] as [$dx, $dy]) {
                    if ($dem[$y + $dy][$x + $dx] < $dem[$y][$x]) {
                        $isSink = false;
                        break;
                    }
                }
                if ($isSink) {
                    $sinks++;
                }
            }
        }

        return $sinks;
    }
}
