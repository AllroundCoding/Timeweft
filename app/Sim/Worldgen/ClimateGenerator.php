<?php

namespace App\Sim\Worldgen;

use App\Sim\Support\Rng;

final class ClimateGenerator
{
    /** Sea-level temperature on the equator, °C. Raise for a hotter world overall. */
    private const EQUATOR_TEMP = 27.0; // 32.0

    /** Sea-level temperature at the poles, °C. Lower for bigger ice caps. */
    private const POLE_TEMP = -35.0; // -15.0

    /** Shape of the equator→pole falloff: above 1 keeps mid-latitudes temperate and concentrates cold at the poles; 1 is a straight gradient. */
    private const LATITUDE_FALLOFF = 1.8; // 1.4

    /** °C lost per unit of elevation (lapse rate). Raise for colder mountains — more alpine snow and tundra. */
    private const LAPSE = 6.5; // 7.0

    /** Moisture lost crossing each flat land cell. Raise for drier continental interiors — bigger inland deserts. */
    private const CONTINENTAL_DRYING = 0.005; // 0.02

    /** Extra moisture wrung out climbing a windward slope. Raise for stronger rain shadows — drier leeward deserts. */
    private const OROGRAPHIC_WRINGING = 0.8; // 1.5

    /** Rainfall boost on a windward upslope. Raise for wetter mountain faces. */
    private const OROGRAPHIC_LIFT = 5.0; // 4.0

    /** Temperature of peak farmland suitability, °C. Shifts which latitude band is most fertile. */
    private const FERTILITY_OPTIMUM = 15.0; // 18.0

    /** How far from that optimum land stays farmable. Raise so more of the world is arable; lower for a narrow fertile band. */
    private const FERTILITY_SPREAD = 14.0; // 18.0

    public static function generate(Rng $rng, Substrate $substrate, Circulation $circulation): Climate
    {
        $temperature = [];
        $precipitation = [];
        $fertility = [];
        $biome = [];

        $width = $substrate->width;
        $height = $substrate->height;
        $equator = ($height - 1) / 2.0;

        // True spherical noise for the climate bands — seeded off the world RNG so each seed wobbles its own way.
        $tempNoise = new FractalNoise($rng->stream('climate-temp')->int(0, 2_000_000_000), 0.015);
        $precipNoise = new FractalNoise($rng->stream('climate-precip')->int(0, 2_000_000_000), 0.02);

        for ($y = 0; $y < $height; $y++) {
            $baseLatitude = $equator > 0.0 ? abs($y - $equator) / $equator : 0.0;

            $temperatureRow = [];
            $precipitationRow = [];
            $fertilityRow = [];
            $biomeRow = [];

            for ($x = 0; $x < $width; $x++) {

                // 1. LATITUDINAL WOBBLES (Using Spherical 3D Noise)
                $tWobble = $tempNoise->fbmSpherical((float) $x, (float) $y, (float) $width, (float) $height) * 0.15;
                $wobbledTempLat = self::clamp($baseLatitude + $tWobble, 0.0, 1.0);
                $baseTemp = self::EQUATOR_TEMP + (self::POLE_TEMP - self::EQUATOR_TEMP) * $wobbledTempLat ** self::LATITUDE_FALLOFF;

                $pWobble = $precipNoise->fbmSpherical((float) $x, (float) $y, (float) $width, (float) $height) * 0.20;
                $wobbledPrecipLat = self::clamp($baseLatitude + $pWobble, 0.0, 1.0);
                $precipLat = self::clamp(0.5 + 0.4 * cos(3.0 * M_PI * $wobbledPrecipLat), 0.20, 0.95);

                $elevation = $substrate->elevationAt($x, $y);
                $land = $elevation > 0.0;
                $elevHeight = max(0.0, $elevation);

                // Fetch dynamic wind vector
                [$u, $v] = $circulation->windAt($x, $y);

                $moisture = 1.0;
                $upslope = 0.0;
                $coastalModeration = 0.0;

                if ($land) {
                    // 2. RAYCAST RAIN SHADOWS (Globe-Wrapping)
                    $oceanFound = false;
                    $mountainBlocks = 0.0;
                    $raySteps = 12;

                    for ($step = 1; $step <= $raySteps; $step++) {
                        $checkX = (int) round($x - ($u * $step));
                        $checkY = (int) round($y - ($v * $step));

                        // SPHERICAL WRAP: Wrap Longitude
                        $checkX = (($checkX % $width) + $width) % $width;

                        // SPHERICAL WRAP: If wind casts over the pole, it hits the ice cap (acts as ocean/open air)
                        if ($checkY < 0 || $checkY >= $height) {
                            $oceanFound = true;
                            break;
                        }

                        $checkElev = $substrate->elevationAt($checkX, $checkY);
                        if ($checkElev <= 0.0) {
                            $oceanFound = true; // Found the ocean!
                            break;
                        } else {
                            $mountainBlocks += max(0.0, $checkElev);
                        }
                    }

                    if (! $oceanFound) {
                        $moisture = max(0.0, 1.0 - ($raySteps * self::CONTINENTAL_DRYING) - ($mountainBlocks * self::OROGRAPHIC_WRINGING));
                    }

                    // Immediate localized upslope (Wrap Longitude)
                    $immediateUpwindX = (int) round($x - $u);
                    $immediateUpwindY = (int) round($y - $v);
                    $immediateUpwindX = (($immediateUpwindX % $width) + $width) % $width;

                    $immediateUpwindElev = ($immediateUpwindY >= 0 && $immediateUpwindY < $height)
                        ? max(0.0, $substrate->elevationAt($immediateUpwindX, $immediateUpwindY)) : 0.0;

                    $upslope = max(0.0, $elevHeight - $immediateUpwindElev);

                    // 3. COASTAL CURRENT MODERATION (Globe-Wrapping)
                    foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$dx, $dy]) {
                        $nx = (($x + $dx) % $width + $width) % $width; // Wrap Longitude
                        $ny = $y + $dy;

                        if ($ny >= 0 && $ny < $height) { // Cap Latitude
                            if ($substrate->elevationAt($nx, $ny) <= 0.0) {
                                $coastalModeration += $circulation->currentTemp[$ny][$nx];
                            }
                        }
                    }
                }

                // 4. FINAL CLIMATE ASSIGNMENT
                $cellTemperature = $baseTemp - (self::LAPSE * $elevHeight) + ($coastalModeration * 4.0);

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

        return new Climate($width, $height, $temperature, $precipitation, $fertility, $biome);
    }

    private static function fertility(float $temperature, float $precipitation, float $slope): float
    {
        if ($temperature < -10.0) {
            return 0.0;
        }
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
        if ($edge0 === $edge1) {
            return $value < $edge0 ? 0.0 : 1.0;
        }
        $t = self::clamp(($value - $edge0) / ($edge1 - $edge0), 0.0, 1.0);

        return $t * $t * (3.0 - 2.0 * $t);
    }

    private static function clamp(float $value, float $low, float $high): float
    {
        return max($low, min($high, $value));
    }
}
