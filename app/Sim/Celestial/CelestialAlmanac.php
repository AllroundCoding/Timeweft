<?php

namespace App\Sim\Celestial;

use App\Sim\Time\TharadiCalendar;

/**
 * The celestial almanac (design doc 17): the cheapest natural system, because the canonical tick already
 * does the work. The integer clock is ground truth; the calendar is projected from it ({@see TharadiCalendar}),
 * and so are the sun's orbital position, the moons' phases, the tides, and notable alignments — all just
 * more deterministic projections off the same integer. Zero stored state, perfectly reproducible, and (by
 * design) **not** wired into the existing seasons/economy: this is the additive primitive that later,
 * deliberate couplings (tides → fishing windows, alignments → festivals, distance → long climate cycles)
 * will read. Project it; never agent-simulate it.
 *
 * A world's sky is a fixed set of orbital constants (axial tilt, eccentricity, its moons), supplied by a
 * preset like {@see vaeris()} — the same data-driven pattern as the region/goods registries.
 */
final class CelestialAlmanac
{
    /** Circular phase distance within which two bodies count as aligned (a fraction of a full cycle). */
    public const ALIGNMENT_TOLERANCE = 0.02;

    /** Dominant-moon phase alignment (|cos|) at or above which the tides run spring rather than neap. */
    public const SPRING_TIDE_THRESHOLD = 0.9;

    private const PHASE_NAMES = [
        'New', 'Waxing Crescent', 'First Quarter', 'Waxing Gibbous',
        'Full', 'Waning Gibbous', 'Last Quarter', 'Waning Crescent',
    ];

    /**
     * @param  float  $axialTiltDegrees  the obliquity that drives the sun's declination (and so the seasons)
     * @param  float  $eccentricity  orbital eccentricity, 0 (circular) .. <1; the distance-to-sun swing
     * @param  float  $perihelionYearFraction  where in the orbit (0..1) the world is nearest the sun
     * @param  list<Moon>  $moons  the world's moons, in a fixed order
     */
    public function __construct(
        private float $axialTiltDegrees,
        private float $eccentricity,
        private float $perihelionYearFraction,
        private array $moons,
    ) {}

    /**
     * The Vaeris sky: a modest tilt and near-circular orbit, a great moon Lunara whose 30-day cycle keeps
     * step with the months, and a small swift moon Khoros that drifts against it (so their alignments are
     * rare and ominous). Easily replaced per world.
     */
    public static function vaeris(): self
    {
        return new self(
            axialTiltDegrees: 23.5,
            eccentricity: 0.05,
            perihelionYearFraction: 0.0,
            moons: [
                new Moon('Lunara', synodicPeriodDays: 30.0, tidalPull: 1.0, phaseOffsetDays: 0.0),
                new Moon('Khoros', synodicPeriodDays: 9.0, tidalPull: 0.4, phaseOffsetDays: 3.0),
            ],
        );
    }

    public function forTick(int $tick): CelestialState
    {
        $totalDays = $tick / TharadiCalendar::HOURS_PER_DAY;
        $yearFraction = self::frac($totalDays / TharadiCalendar::DAYS_PER_YEAR);

        $solarLongitude = 2.0 * M_PI * $yearFraction;
        $solarDeclination = $this->axialTiltDegrees * sin($solarLongitude);
        $solarDistance = 1.0 - $this->eccentricity * cos(2.0 * M_PI * ($yearFraction - $this->perihelionYearFraction));

        $moonStates = [];
        $tideNumerator = 0.0;
        $tidePull = 0.0;
        $dominantPull = -1.0;
        $dominantAlignment = 0.0;
        foreach ($this->moons as $moon) {
            $phase = self::frac(($totalDays + $moon->phaseOffsetDays) / $moon->synodicPeriodDays);
            $illumination = (1.0 - cos(2.0 * M_PI * $phase)) / 2.0;
            $moonStates[] = new MoonState($moon->name, $phase, $illumination, self::phaseName($phase));

            // Tidal amplitude peaks at new and full (sun and moon aligned) and vanishes at the quarters.
            $alignment = abs(cos(2.0 * M_PI * $phase));
            $tideNumerator += $moon->tidalPull * $alignment;
            $tidePull += $moon->tidalPull;
            if ($moon->tidalPull > $dominantPull) {
                $dominantPull = $moon->tidalPull;
                $dominantAlignment = $alignment;
            }
        }

        $tideLevel = $tidePull > 0.0 ? $tideNumerator / $tidePull : 0.0;

        return new CelestialState(
            tick: $tick,
            yearFraction: $yearFraction,
            solarLongitude: $solarLongitude,
            solarDeclination: $solarDeclination,
            solarDistance: $solarDistance,
            moons: $moonStates,
            tideLevel: $tideLevel,
            springTide: $dominantAlignment >= self::SPRING_TIDE_THRESHOLD,
            alignments: self::alignments($moonStates),
        );
    }

    /**
     * Notable syzygies in effect this tick — a moon new (conjunction) or full (opposition) against the sun,
     * and any pair of moons sharing a phase. Ordered deterministically (sun events in moon order, then
     * pairings in index order) so the chronicle reads the same on every run.
     *
     * @param  list<MoonState>  $moons
     * @return list<string>
     */
    private static function alignments(array $moons): array
    {
        $out = [];
        foreach ($moons as $moon) {
            if (self::near($moon->phase, 0.0)) {
                $out[] = "{$moon->name} is new — conjunction with the sun";
            } elseif (self::near($moon->phase, 0.5)) {
                $out[] = "{$moon->name} is full — opposition to the sun";
            }
        }

        $count = count($moons);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (self::near($moons[$i]->phase, $moons[$j]->phase)) {
                    $out[] = "{$moons[$i]->name} and {$moons[$j]->name} align";
                }
            }
        }

        return $out;
    }

    /** Whether two phase positions sit within {@see ALIGNMENT_TOLERANCE} on the circular 0..1 cycle. */
    private static function near(float $a, float $b): bool
    {
        $distance = abs($a - $b);

        return min($distance, 1.0 - $distance) <= self::ALIGNMENT_TOLERANCE;
    }

    private static function phaseName(float $phase): string
    {
        $index = ((int) round($phase * 8.0)) % count(self::PHASE_NAMES);

        return self::PHASE_NAMES[$index];
    }

    /** Fractional part in [0, 1), correct for negative inputs too. */
    private static function frac(float $value): float
    {
        return $value - floor($value);
    }
}
