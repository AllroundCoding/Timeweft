<?php

namespace App\Sim\Worldgen;

/**
 * Derives the {@see Climate} surface from the frozen {@see Substrate} (design doc 13; TWT-132).
 *
 * Temperature falls from a warm equator to cold poles and drops with elevation (lapse rate).
 * Precipitation starts from latitude bands — wet at the equator (ITCZ), dry in the subtropics, wetter at
 * mid-latitudes — then a prevailing west wind carries moisture inland: it recharges over sea, wrings out
 * climbing windward slopes (so ranges are wet on the upwind face) and runs dry in their lee and across
 * deep continental interiors. Fertility is high where warmth and moisture meet on workable ground.
 *
 * Deterministic and framework-free: a pure function of the substrate, so the same seed reproduces the
 * same climate. A first pass — a fixed prevailing wind stands in for the circulation model (TWT-76), and
 * climate zones / cryosphere / soil-from-rock / disease are left for later.
 */
final class ClimateGenerator
{
    /** Sea-level temperature on the equator, °C. Raise for a hotter world overall. */
    private const EQUATOR_TEMP = 40.0; // 32.0

    /** Sea-level temperature at the poles, °C. Lower for bigger ice caps. */
    private const POLE_TEMP = -15.0; // -15.0

    /** Shape of the equator→pole falloff: above 1 keeps mid-latitudes temperate and concentrates cold at the poles; 1 is a straight gradient. */
    private const LATITUDE_FALLOFF = 1.6; // 1.4

    /** °C lost per unit of elevation (lapse rate). Raise for colder mountains — more alpine snow and tundra. */
    private const LAPSE = 4.0; // 7.0

    /** Moisture the air regains crossing each sea cell. Raise for wetter coasts and a wetter world. */
    private const SEA_RECHARGE = 0.35; // 0.30

    /** Moisture lost crossing each flat land cell. Raise for drier continental interiors — bigger inland deserts. */
    private const CONTINENTAL_DRYING = 0.04; // 0.02

    /** Extra moisture wrung out climbing a windward slope. Raise for stronger rain shadows — drier leeward deserts. */
    private const OROGRAPHIC_WRINGING = 1.8; // 1.5

    /** Rainfall boost on a windward upslope. Raise for wetter mountain faces. */
    private const OROGRAPHIC_LIFT = 5.0; // 4.0

    /** Temperature of peak farmland suitability, °C. Shifts which latitude band is most fertile. */
    private const FERTILITY_OPTIMUM = 20.0; // 18.0

    /** How far from that optimum land stays farmable. Raise so more of the world is arable; lower for a narrow fertile band. */
    private const FERTILITY_SPREAD = 18.0; // 18.0

    public static function generate(Substrate $substrate): Climate
    {
        $temperature = [];
        $precipitation = [];
        $fertility = [];
        $biome = [];

        $equator = ($substrate->height - 1) / 2.0;

        for ($y = 0; $y < $substrate->height; $y++) {
            $latitude = $equator > 0.0 ? abs($y - $equator) / $equator : 0.0; // 0 at the equator … 1 at a pole
            $precipLat = self::clamp(0.5 + 0.4 * cos(3.0 * M_PI * $latitude), 0.20, 0.95);
            $baseTemp = self::EQUATOR_TEMP + (self::POLE_TEMP - self::EQUATOR_TEMP) * $latitude ** self::LATITUDE_FALLOFF;

            $moisture = 1.0; // air enters saturated at the upwind (west) edge
            $temperatureRow = [];
            $precipitationRow = [];
            $fertilityRow = [];
            $biomeRow = [];

            for ($x = 0; $x < $substrate->width; $x++) {
                $elevation = $substrate->elevationAt($x, $y);
                $land = $elevation > 0.0;
                $height = max(0.0, $elevation);

                $cellTemperature = $baseTemp - self::LAPSE * $height;

                if ($land) {
                    $upwind = $x > 0 ? max(0.0, $substrate->elevationAt($x - 1, $y)) : 0.0;
                    $upslope = max(0.0, $height - $upwind);
                    $cellPrecipitation = self::clamp($precipLat * (0.45 + 0.55 * $moisture) * (1.0 + self::OROGRAPHIC_LIFT * $upslope), 0.0, 1.0);
                    $moisture = max(0.0, $moisture - self::CONTINENTAL_DRYING - self::OROGRAPHIC_WRINGING * $upslope);
                } else {
                    $moisture = min(1.0, $moisture + self::SEA_RECHARGE);
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

    /** Agrarian potential: warmth near the optimum, enough rain, on ground gentle enough to work. */
    private static function fertility(float $temperature, float $precipitation, float $slope): float
    {
        if ($temperature < -10.0) {
            return 0.0; // frozen ground yields nothing
        }

        $warmth = exp(-(($temperature - self::FERTILITY_OPTIMUM) / self::FERTILITY_SPREAD) ** 2);
        $moisture = self::smoothstep(0.10, 0.60, $precipitation);
        $workable = 1.0 - self::clamp($slope * 0.6, 0.0, 0.8); // steep ground is hard to farm

        return self::clamp($warmth * $moisture * $workable, 0.0, 1.0);
    }

    /** A coarse biome from temperature, precipitation, and whether the cell is land. */
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

    /** Hermite smoothstep — a soft 0→1 ramp between two edges. */
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
