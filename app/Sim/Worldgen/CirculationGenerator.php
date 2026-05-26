<?php

namespace App\Sim\Worldgen;

use App\Sim\Support\Rng;

final class CirculationGenerator
{
    /** How strongly mountains deflect wind. */
    private const WIND_DEFLECTION = 0.8;

    /** Base noise frequency for organic meandering winds. */
    private const WIND_NOISE_FREQ = 0.03;

    public static function generate(Rng $rng, Substrate $substrate): Circulation
    {
        $windU = [];
        $windV = [];
        $currentU = [];
        $currentV = [];
        $currentTemp = [];

        $width = $substrate->width;
        $height = $substrate->height;
        $equator = ($height - 1) / 2.0;

        // Noise generators to wobble the wind vectors
        $noiseU = new FractalNoise($rng->stream('wind', 0)->int(0, 2_000_000_000), self::WIND_NOISE_FREQ);
        $noiseV = new FractalNoise($rng->stream('wind', 1)->int(0, 2_000_000_000), self::WIND_NOISE_FREQ);

        for ($y = 0; $y < $height; $y++) {
            $windU[$y] = [];
            $windV[$y] = [];
            $currentU[$y] = [];
            $currentV[$y] = [];
            $currentTemp[$y] = [];

            // Latitude: 0.0 (Equator) to 1.0 (Poles)
            $latitude = $equator > 0.0 ? abs($y - $equator) / $equator : 0.0;
            $isNorthernHemisphere = $y < $equator;

            for ($x = 0; $x < $width; $x++) {
                // 1. BASE LATITUDINAL WIND
                $u = 0.0;
                $v = 0.0;
                $verticalDirection = $isNorthernHemisphere ? 1.0 : -1.0; // 1.0 is pointing South (towards equator)

                if ($latitude < 0.33) {
                    // Trade Winds: East to West, towards equator
                    $u = -1.0;
                    $v = $verticalDirection;
                } elseif ($latitude < 0.66) {
                    // Westerlies: West to East, towards poles
                    $u = 1.0;
                    $v = -$verticalDirection;
                } else {
                    // Polar Easterlies: East to West, towards equator
                    $u = -1.0;
                    $v = $verticalDirection;
                }

                // Add organic meandering
                $u += $noiseU->fbmSpherical((float)$x, (float)$y, (float)$width, (float)$height) * 0.5;
                $v += $noiseV->fbmSpherical((float)$x, (float)$y, (float)$width, (float)$height) * 0.5;

                // Normalize wind vector
                $len = hypot($u, $v) ?: 1.0;
                $u /= $len;
                $v /= $len;

                $isLand = $substrate->elevationAt($x, $y) > 0.0;

                if ($isLand) {
                    // 2. TERRAIN DEFLECTION
                    // X wraps around the globe, Y caps at the poles
                    $nextX = ($x + 1) % $width;
                    $prevX = ($x - 1 + $width) % $width;
                    $nextY = min($height - 1, $y + 1);
                    $prevY = max(0, $y - 1);

                    $dx = $substrate->elevationAt($nextX, $y) - $substrate->elevationAt($prevX, $y);
                    $dy = $substrate->elevationAt($x, $nextY) - $substrate->elevationAt($x, $prevY);

                    // Dot product of wind vector and slope gradient
                    $facingSlope = ($u * $dx) + ($v * $dy);

                    if ($facingSlope > 0) {
                        // Wind is blowing UPHILL. Deflect it by rotating it perpendicular to the slope.
                        // (Cross product approximation)
                        $deflectU = -$dy;
                        $deflectV = $dx;

                        $dLen = hypot($deflectU, $deflectV) ?: 1.0;
                        $u = ($u * (1.0 - self::WIND_DEFLECTION)) + (($deflectU / $dLen) * self::WIND_DEFLECTION);
                        $v = ($v * (1.0 - self::WIND_DEFLECTION)) + (($deflectV / $dLen) * self::WIND_DEFLECTION);

                        // Re-normalize after deflection
                        $len = hypot($u, $v) ?: 1.0;
                        $u /= $len;
                        $v /= $len;
                    }

                    // Land has no ocean current
                    $cU = 0.0; $cV = 0.0; $cT = 0.0;

                } else {
                    // 3. OCEAN CURRENTS & GYRES
                    // Base current matches wind
                    $cU = $u;
                    $cV = $v;

                    // Coastline deflection check (Look ahead in the direction of flow)
                    $lookX = (int)round($x + $cU * 2.0);
                    $lookY = (int)round($y + $cV * 2.0);

                    // Bound checks: X wraps securely (handling negative PHP modulo), Y caps
                    $lookX = (($lookX % $width) + $width) % $width;
                    $lookY = max(0, min($height - 1, $lookY));

                    // If the current is about to hit land, deflect it 90 degrees
                    if ($substrate->elevationAt($lookX, $lookY) > 0.0) {
                        // Hemisphere determines default gyre rotation (North = Clockwise, South = Counter)
                        if ($isNorthernHemisphere) {
                            $temp = $cU; $cU = -$cV; $cV = $temp; // Rotate 90 deg clockwise
                        } else {
                            $temp = $cU; $cU = $cV; $cV = -$temp; // Rotate 90 deg counter
                        }
                    }

                    // Calculate Current Temperature based on vertical movement
                    // If moving away from equator (-verticalDirection), it's carrying warm water
                    // If moving towards equator (+verticalDirection), it's carrying cold water
                    $cT = ($cV * -$verticalDirection);
                }

                $windU[$y][$x] = $u;
                $windV[$y][$x] = $v;
                $currentU[$y][$x] = $cU;
                $currentV[$y][$x] = $cV;
                $currentTemp[$y][$x] = $cT ?? 0.0;
            }
        }

        return new Circulation($width, $height, $windU, $windV, $currentU, $currentV, $currentTemp);
    }
}
