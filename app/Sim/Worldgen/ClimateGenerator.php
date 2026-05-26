<?php

namespace App\Sim\Worldgen;

final class ClimateGenerator
{
    // TODO allow adjustments when GUI is build for world generation (within limits), earth like defaults for now
    private const EQUATOR_TEMP = 27.0;
    private const POLE_TEMP = -25.0;
    private const LATITUDE_FALLOFF = 1.8;
    private const LAPSE = 6.5;
    private const CONTINENTAL_DRYING = 0.005;
    private const OROGRAPHIC_WRINGING = 0.8;
    private const OROGRAPHIC_LIFT = 5.0;
    private const FERTILITY_OPTIMUM = 15.0;
    private const FERTILITY_SPREAD = 14.0;

    // CHANGED: Added Circulation parameter
    public static function generate(Substrate $substrate, Circulation $circulation): Climate
    {
        $temperature = [];
        $precipitation = [];
        $fertility = [];
        $biome = [];

        $equator = ($substrate->height - 1) / 2.0;

        // Wobble maps to break straight latitudinal bands
        $tempNoise = new FractalNoise(42, 0.015);
        $precipNoise = new FractalNoise(43, 0.02);

        for ($y = 0; $y < $substrate->height; $y++) {
            $baseLatitude = $equator > 0.0 ? abs($y - $equator) / $equator : 0.0;

            $temperatureRow = [];
            $precipitationRow = [];
            $fertilityRow = [];
            $biomeRow = [];

            for ($x = 0; $x < $substrate->width; $x++) {

                // 1. LATITUDINAL WOBBLES
                $tWobble = $tempNoise->fbmSpherical((float)$x, (float)$y, (float)$substrate->width, (float)$substrate->height) * 0.15;
                $wobbledTempLat = self::clamp($baseLatitude + $tWobble, 0.0, 1.0);
                $baseTemp = self::EQUATOR_TEMP + (self::POLE_TEMP - self::EQUATOR_TEMP) * $wobbledTempLat ** self::LATITUDE_FALLOFF;

                $pWobble = $precipNoise->fbmSpherical((float)$x, (float)$y, (float)$substrate->width, (float)$substrate->height) * 0.20;
                $wobbledPrecipLat = self::clamp($baseLatitude + $pWobble, 0.0, 1.0);
                $precipLat = self::clamp(0.5 + 0.4 * cos(3.0 * M_PI * $wobbledPrecipLat), 0.20, 0.95);

                $elevation = $substrate->elevationAt($x, $y);
                $land = $elevation > 0.0;
                $height = max(0.0, $elevation);

                // Fetch dynamic wind vector
                [$u, $v] = $circulation->windAt($x, $y);

                $moisture = 1.0;
                $upslope = 0.0;
                $coastalModeration = 0.0;

                if ($land) {
                    // 2. RAYCAST RAIN SHADOWS
                    // Look "upwind" to see if we are blocked by mountains or deep inland
                    $oceanFound = false;
                    $mountainBlocks = 0.0;
                    $raySteps = 12; // Look up to 12 cells back into the wind

                    for ($step = 1; $step <= $raySteps; $step++) {
                        $checkX = (int)round($x - ($u * $step));
                        $checkY = (int)round($y - ($v * $step));

                        // If wind is coming from off-map, assume it's wet ocean air
                        if ($checkX < 0 || $checkX >= $substrate->width || $checkY < 0 || $checkY >= $substrate->height) {
                            $oceanFound = true;
                            break;
                        }

                        $checkElev = $substrate->elevationAt($checkX, $checkY);
                        if ($checkElev <= 0.0) {
                            $oceanFound = true; // Found the ocean!
                            break;
                        } else {
                            $mountainBlocks += max(0.0, $checkElev); // Accumulate rain shadow
                        }
                    }

                    if (!$oceanFound) {
                        $moisture = max(0.0, 1.0 - ($raySteps * self::CONTINENTAL_DRYING) - ($mountainBlocks * self::OROGRAPHIC_WRINGING));
                    }

                    // Calculate immediate localized upslope for rain dumps
                    $immediateUpwindX = (int)round($x - $u);
                    $immediateUpwindY = (int)round($y - $v);
                    $immediateUpwindElev = ($immediateUpwindX >= 0 && $immediateUpwindX < $substrate->width && $immediateUpwindY >= 0 && $immediateUpwindY < $substrate->height)
                        ? max(0.0, $substrate->elevationAt($immediateUpwindX, $immediateUpwindY)) : 0.0;

                    $upslope = max(0.0, $height - $immediateUpwindElev);

                    // 3. COASTAL CURRENT TEMPERATURE MODERATION
                    // Check adjacent cells for ocean to see if a warm/cold current hits this coast
                    foreach ([[-1,0],[1,0],[0,-1],[0,1]] as [$dx, $dy]) {
                        $nx = $x + $dx; $ny = $y + $dy;
                        if ($nx >= 0 && $nx < $substrate->width && $ny >= 0 && $ny < $substrate->height) {
                            if ($substrate->elevationAt($nx, $ny) <= 0.0) {
                                $coastalModeration += $circulation->currentTemp[$ny][$nx];
                            }
                        }
                    }
                }

                // 4. FINAL CLIMATE ASSIGNMENT
                // Base Temp - Altitude Drop + Current Moderation
                $cellTemperature = $baseTemp - (self::LAPSE * $height) + ($coastalModeration * 4.0);

                if ($land) {
                    $cellPrecipitation = self::clamp($precipLat * (0.45 + 0.55 * $moisture) * (1.0 + self::OROGRAPHIC_LIFT * $upslope), 0.0, 1.0);
                } else {
                    $cellPrecipitation = 0.6 * $precipLat;
                }

                $temperatureRow[] = $cellTemperature;
                $precipitationRow[] = $cellPrecipitation;
                $fertilityRow[] = $land ? self::fertility($cellTemperature, $cellPrecipitation, $substrate->slopeAt($x, $y)) : 0.0;
                $biomeRow[] = self::classify($land, $cellTemperature, $cellPrecipitation);
            }

            $temperature[] = $temperatureRow;
            $precipitation[] = $precipitationRow;
            $fertility[] = $fertilityRow;
            $biome[] = $biomeRow;
        }

        return new Climate($substrate->width, $substrate->height, $temperature, $precipitation, $fertility, $biome);
    }

    private static function fertility(float $temperature, float $precipitation, float $slope): float
    {
        if ($temperature < -10.0) return 0.0;
        $warmth = exp(-(($temperature - self::FERTILITY_OPTIMUM) / self::FERTILITY_SPREAD) ** 2);
        $moisture = self::smoothstep(0.10, 0.60, $precipitation);
        $workable = 1.0 - self::clamp($slope * 0.6, 0.0, 0.8);
        return self::clamp($warmth * $moisture * $workable, 0.0, 1.0);
    }

    private static function classify(bool $land, float $temperature, float $precipitation): Biome
    {
        return match (true) {
            ! $land => Biome::Ocean,
            $temperature < -10.0 => Biome::Ice,
            $temperature < 0.0 => Biome::Tundra,
            $precipitation < 0.15 => Biome::Desert,
            $precipitation < 0.35 => Biome::Shrubland,
            $precipitation < 0.60 => Biome::Grassland,
            $temperature >= 22.0 && $precipitation >= 0.75 => Biome::Rainforest,
            default => Biome::Forest,
        };
    }

    private static function smoothstep(float $edge0, float $edge1, float $value): float
    {
        if ($edge0 === $edge1) return $value < $edge0 ? 0.0 : 1.0;
        $t = self::clamp(($value - $edge0) / ($edge1 - $edge0), 0.0, 1.0);
        return $t * $t * (3.0 - 2.0 * $t);
    }

    private static function clamp(float $value, float $low, float $high): float
    {
        return max($low, min($high, $value));
    }
}
