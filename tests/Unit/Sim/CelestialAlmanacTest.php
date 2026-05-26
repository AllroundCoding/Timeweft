<?php

namespace Tests\Unit\Sim;

use App\Sim\Celestial\CelestialAlmanac;
use App\Sim\Celestial\Moon;
use App\Sim\Time\TharadiCalendar;
use PHPUnit\Framework\TestCase;

/**
 * TWT-106 — the celestial almanac is a pure projection off the canonical tick: same tick → same sky,
 * with no stored state and no RNG. It is additive (not wired into seasons/economy), so the canonical
 * run stays byte-identical; these tests pin the projection's shape.
 */
class CelestialAlmanacTest extends TestCase
{
    private const TICKS_PER_DAY = TharadiCalendar::HOURS_PER_DAY;

    private const TICKS_PER_YEAR = TharadiCalendar::HOURS_PER_DAY * TharadiCalendar::DAYS_PER_YEAR;

    public function test_the_almanac_is_a_deterministic_projection_of_the_tick(): void
    {
        $this->assertEquals(
            CelestialAlmanac::vaeris()->forTick(123_456),
            CelestialAlmanac::vaeris()->forTick(123_456),
            'same tick → identical sky, every run',
        );
    }

    public function test_the_orbit_progresses_through_the_year(): void
    {
        $almanac = CelestialAlmanac::vaeris();

        $newYear = $almanac->forTick(0);
        $this->assertEqualsWithDelta(0.0, $newYear->yearFraction, 1e-9);

        $midYear = $almanac->forTick((int) (self::TICKS_PER_YEAR / 2));
        $this->assertEqualsWithDelta(0.5, $midYear->yearFraction, 1e-9);

        // The longitude wraps back to the start after a full year.
        $this->assertEqualsWithDelta(0.0, $almanac->forTick(self::TICKS_PER_YEAR)->yearFraction, 1e-9);
        $this->assertGreaterThanOrEqual(0.0, $midYear->solarLongitude);
        $this->assertLessThan(2.0 * M_PI, $midYear->solarLongitude);
    }

    public function test_solar_declination_swings_with_the_seasons(): void
    {
        $almanac = CelestialAlmanac::vaeris();

        // Equinoxes (year start and midpoint): the sun sits on the equator.
        $this->assertEqualsWithDelta(0.0, $almanac->forTick(0)->solarDeclination, 1e-9);
        $this->assertEqualsWithDelta(0.0, $almanac->forTick((int) (self::TICKS_PER_YEAR / 2))->solarDeclination, 1e-6);

        // Solstices (quarter and three-quarter): the declination reaches ±the axial tilt.
        $summer = $almanac->forTick((int) (self::TICKS_PER_YEAR / 4))->solarDeclination;
        $winter = $almanac->forTick((int) (self::TICKS_PER_YEAR * 3 / 4))->solarDeclination;
        $this->assertEqualsWithDelta(23.5, $summer, 0.05);
        $this->assertEqualsWithDelta(-23.5, $winter, 0.05);
    }

    public function test_a_moon_runs_through_its_phases(): void
    {
        $almanac = CelestialAlmanac::vaeris();

        // Lunara: a 30-day synodic cycle, new at tick 0, full half a cycle later.
        $new = $almanac->forTick(0)->moon('Lunara');
        $this->assertNotNull($new);
        $this->assertEqualsWithDelta(0.0, $new->illumination, 1e-9, 'new moon shows no light');
        $this->assertSame('New', $new->phaseName);

        $full = $almanac->forTick(15 * self::TICKS_PER_DAY)->moon('Lunara');
        $this->assertNotNull($full);
        $this->assertEqualsWithDelta(1.0, $full->illumination, 1e-9, 'full moon is fully lit');
        $this->assertSame('Full', $full->phaseName);

        // After one full synodic period it returns to new.
        $returned = $almanac->forTick(30 * self::TICKS_PER_DAY)->moon('Lunara');
        $this->assertNotNull($returned);
        $this->assertEqualsWithDelta(0.0, $returned->phase, 1e-9);
    }

    public function test_tides_run_spring_at_the_full_moon_and_neap_at_the_quarter(): void
    {
        $almanac = CelestialAlmanac::vaeris();

        $spring = $almanac->forTick(15 * self::TICKS_PER_DAY); // Lunara full
        $this->assertTrue($spring->springTide, 'sun and the great moon aligned → spring tide');
        $this->assertGreaterThan(0.9, $spring->tideLevel);

        $neap = $almanac->forTick((int) (7.5 * self::TICKS_PER_DAY)); // Lunara at first quarter
        $this->assertFalse($neap->springTide, 'the great moon at its quarter → neap tide');

        // Tide level is always a normalised amplitude.
        foreach ([0, 100, 5_000, 250_000] as $tick) {
            $level = $almanac->forTick($tick)->tideLevel;
            $this->assertGreaterThanOrEqual(0.0, $level);
            $this->assertLessThanOrEqual(1.0, $level);
        }
    }

    public function test_it_reports_alignments(): void
    {
        // A new moon at tick 0 is a conjunction with the sun.
        $vaeris = CelestialAlmanac::vaeris()->forTick(0);
        $this->assertContains('Lunara is new — conjunction with the sun', $vaeris->alignments);

        // Two moons sharing a phase report a pairing; both also read as new at tick 0.
        $twinNew = new CelestialAlmanac(0.0, 0.0, 0.0, [
            new Moon('Aldis', synodicPeriodDays: 30.0),
            new Moon('Bel', synodicPeriodDays: 10.0),
        ]);
        $this->assertContains('Aldis and Bel align', $twinNew->forTick(0)->alignments);

        // Off a shared phase, no pairing is reported.
        $apart = $twinNew->forTick(2 * self::TICKS_PER_DAY); // Aldis at 1/15, Bel at 1/5 — well apart
        $this->assertNotContains('Aldis and Bel align', $apart->alignments);
    }
}
