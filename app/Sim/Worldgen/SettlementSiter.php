<?php

namespace App\Sim\Worldgen;

/**
 * Sites settlements on the generated world (design doc 13/14; TWT-82) — they emerge from the geography
 * instead of being hand-placed. Every land cell is scored for **suitability** (fresh water, arable
 * climate, defensibility, trade-node value), settlements are seeded at the local peaks of that score
 * spaced apart, and each takes a **tier** (hamlet → city) from its hinterland's fertility and its trade
 * position — which is why great cities sit on river mouths, confluences, and coasts.
 *
 * Pure, framework-free, and deterministic — a function of substrate + climate + hydrology, so the same
 * seed sites the same world. Longitude wraps; latitude is capped at the poles. A first pass: placement +
 * tier; trade-driven growth dynamics and wiring into the live sim come later.
 */
final class SettlementSiter
{
    /** A cell must score at least this to seed a settlement. Lower for a denser, more crowded world. */
    private const MIN_SUITABILITY = 0.45;

    /** River flow above which the water carries trade (a navigable river). Lower to make more rivers count as trade routes. */
    private const NAVIGABLE_FLOW = 120.0;

    /** Hinterland radius (cells) sampled around a site for its growth potential. Raise so a city needs a bigger fertile catchment. */
    private const CATCHMENT_RADIUS = 4;

    private const WEIGHT_WATER = 0.40;    // fresh water is the first need of any settlement

    private const WEIGHT_ARABLE = 0.30;   // food from the surrounding land

    private const WEIGHT_DEFENSE = 0.10;  // a little high ground helps

    private const WEIGHT_TRADE = 0.20;    // coasts, river mouths and navigable rivers draw commerce

    private const NEIGHBORS4 = [[1, 0], [-1, 0], [0, 1], [0, -1]];

    private const NEIGHBORS8 = [[-1, -1], [0, -1], [1, -1], [-1, 0], [1, 0], [-1, 1], [0, 1], [1, 1]];

    /**
     * @param  int|null  $spacing  minimum cells between settlements; defaults to ~5% of the smaller map side
     * @return list<SettlementSite>
     */
    public static function site(Substrate $substrate, Climate $climate, Hydrology $hydrology, ?int $spacing = null): array
    {
        $width = $substrate->width;
        $height = $substrate->height;
        $spacing = $spacing ?? max(4, (int) round(min($width, $height) * 0.05));

        // 1. Score every land cell for how good a settlement site it is.
        $suitability = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $row[] = $substrate->isLand($x, $y) ? self::suitabilityAt($substrate, $climate, $hydrology, $x, $y) : 0.0;
            }
            $suitability[] = $row;
        }

        // 2. Candidate sites are the local peaks of suitability above the bar — a settlement sits on the
        //    best spot in its neighbourhood, not on every viable cell.
        $candidates = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ($suitability[$y][$x] >= self::MIN_SUITABILITY && self::isLocalPeak($suitability, $x, $y, $width, $height)) {
                    $candidates[] = [$suitability[$y][$x], $x, $y];
                }
            }
        }
        usort($candidates, static fn (array $a, array $b): int => [$b[0], $a[2], $a[1]] <=> [$a[0], $b[2], $b[1]]);

        // 3. Greedily plant the best candidates, keeping them at least $spacing apart (longitude wraps).
        $sites = [];
        foreach ($candidates as [$score, $x, $y]) {
            foreach ($sites as $placed) {
                $dx = abs($x - $placed->x);
                $dx = min($dx, $width - $dx); // the globe wraps east-west
                if (hypot($dx, abs($y - $placed->y)) < $spacing) {
                    continue 2;
                }
            }
            $sites[] = new SettlementSite($x, $y, $score, self::tierAt($substrate, $climate, $hydrology, $x, $y));
        }

        return $sites;
    }

    /** How good a single land cell is to settle — fresh water, food, defensibility, and trade, gated on water. */
    private static function suitabilityAt(Substrate $substrate, Climate $climate, Hydrology $hydrology, int $x, int $y): float
    {
        $water = self::waterAccess($substrate, $hydrology, $x, $y);
        $arable = $climate->fertilityAt($x, $y);
        $defense = min(1.0, $substrate->slopeAt($x, $y) / 0.3);
        $trade = self::tradeValue($substrate, $hydrology, $x, $y);

        $score = self::WEIGHT_WATER * $water
            + self::WEIGHT_ARABLE * $arable
            + self::WEIGHT_DEFENSE * $defense
            + self::WEIGHT_TRADE * $trade;

        return $water < 0.3 ? $score * 0.25 : $score; // a waterless interior barely settles
    }

    /** Proximity to fresh water (0..1): on a river/delta is best, then coast, river- or lake-shore. */
    private static function waterAccess(Substrate $substrate, Hydrology $hydrology, int $x, int $y): float
    {
        if ($hydrology->isDelta($x, $y) || $hydrology->isRiver($x, $y)) {
            return 1.0;
        }

        $best = 0.15; // some groundwater everywhere
        foreach (self::NEIGHBORS4 as [$dx, $dy]) {
            $ny = $y + $dy;
            if ($ny < 0 || $ny >= $substrate->height) {
                continue;
            }
            $nx = ($x + $dx + $substrate->width) % $substrate->width;
            if (! $substrate->isLand($nx, $ny)) {
                $best = max($best, 0.9); // coast
            } elseif ($hydrology->isRiver($nx, $ny)) {
                $best = max($best, 0.85);
            } elseif ($hydrology->isLake($nx, $ny)) {
                $best = max($best, 0.8);
            }
        }

        return $best;
    }

    /** Trade-node value (0..1): a river mouth is prime, then a harbour coast, then a navigable river. */
    private static function tradeValue(Substrate $substrate, Hydrology $hydrology, int $x, int $y): float
    {
        if ($hydrology->isDelta($x, $y)) {
            return 1.0;
        }

        $value = 0.15;
        foreach (self::NEIGHBORS4 as [$dx, $dy]) {
            $ny = $y + $dy;
            if ($ny < 0 || $ny >= $substrate->height) {
                continue;
            }
            $nx = ($x + $dx + $substrate->width) % $substrate->width;
            if (! $substrate->isLand($nx, $ny)) {
                $value = max($value, 0.7); // a harbour
            }
        }
        if ($hydrology->isRiver($x, $y)) {
            $value = max($value, min(0.8, $hydrology->flowAt($x, $y) / self::NAVIGABLE_FLOW));
        }

        return $value;
    }

    /** The tier a site grows to, from its hinterland's mean fertility plus its trade position. */
    private static function tierAt(Substrate $substrate, Climate $climate, Hydrology $hydrology, int $x, int $y): SettlementTier
    {
        $sum = 0.0;
        $count = 0;
        for ($dy = -self::CATCHMENT_RADIUS; $dy <= self::CATCHMENT_RADIUS; $dy++) {
            $ny = $y + $dy;
            if ($ny < 0 || $ny >= $substrate->height) {
                continue;
            }
            for ($dx = -self::CATCHMENT_RADIUS; $dx <= self::CATCHMENT_RADIUS; $dx++) {
                $nx = ($x + $dx + $substrate->width) % $substrate->width;
                $sum += $climate->fertilityAt($nx, $ny);
                $count++;
            }
        }
        $catchment = $sum / max(1, $count);
        $potential = 0.6 * $catchment + 0.4 * self::tradeValue($substrate, $hydrology, $x, $y);

        return match (true) {
            $potential >= 0.55 => SettlementTier::City,
            $potential >= 0.40 => SettlementTier::Town,
            $potential >= 0.25 => SettlementTier::Village,
            default => SettlementTier::Hamlet,
        };
    }

    /**
     * Whether a cell is a local peak of suitability (>= all eight neighbours). Longitude wraps; latitude
     * is capped at the poles.
     *
     * @param  list<list<float>>  $suitability
     */
    private static function isLocalPeak(array $suitability, int $x, int $y, int $width, int $height): bool
    {
        $here = $suitability[$y][$x];
        foreach (self::NEIGHBORS8 as [$dx, $dy]) {
            $ny = $y + $dy;
            if ($ny < 0 || $ny >= $height) {
                continue;
            }
            $nx = ($x + $dx + $width) % $width;
            if ($suitability[$ny][$nx] > $here) {
                return false;
            }
        }

        return true;
    }
}
