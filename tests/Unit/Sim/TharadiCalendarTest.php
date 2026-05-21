<?php

namespace Tests\Unit\Sim;

use App\Sim\Time\TharadiCalendar;
use PHPUnit\Framework\TestCase;

class TharadiCalendarTest extends TestCase
{
    public function test_tick_zero_is_the_first_hour_of_the_new_year(): void
    {
        $date = TharadiCalendar::fromTick(0);

        $this->assertSame(1, $date->year);
        $this->assertSame(0, $date->monthIndex);
        $this->assertSame('Naralis', $date->monthName);
        $this->assertSame('Oasis', $date->season);
        $this->assertSame(1, $date->dayOfMonth);
        $this->assertSame(0, $date->hour);
        $this->assertSame('Ra\'ans', $date->weekdayName);
    }

    public function test_hours_wrap_and_advance_the_day(): void
    {
        $this->assertSame(23, TharadiCalendar::fromTick(23)->hour);

        $nextDay = TharadiCalendar::fromTick(TharadiCalendar::HOURS_PER_DAY);
        $this->assertSame(0, $nextDay->hour);
        $this->assertSame(2, $nextDay->dayOfMonth);
        $this->assertSame('Naras', $nextDay->weekdayName);
    }

    public function test_month_boundary(): void
    {
        // Day-of-year 30 is the first day of the second month (Jarethis).
        $tick = TharadiCalendar::DAYS_PER_MONTH * TharadiCalendar::HOURS_PER_DAY;
        $date = TharadiCalendar::fromTick($tick);

        $this->assertSame(1, $date->monthIndex);
        $this->assertSame('Jarethis', $date->monthName);
        $this->assertSame(1, $date->dayOfMonth);
        $this->assertSame('Oasis', $date->season);
    }

    public function test_season_flips_to_sandstorm_at_the_third_month(): void
    {
        $lastOasisDay = TharadiCalendar::fromTick((60 * TharadiCalendar::HOURS_PER_DAY) - TharadiCalendar::HOURS_PER_DAY);
        $this->assertSame('Jarethis', $lastOasisDay->monthName);
        $this->assertSame('Oasis', $lastOasisDay->season);

        $firstSandstormDay = TharadiCalendar::fromTick(60 * TharadiCalendar::HOURS_PER_DAY);
        $this->assertSame('Kalimos', $firstSandstormDay->monthName);
        $this->assertSame('Sandstorm', $firstSandstormDay->season);
    }

    public function test_only_naralis_and_jarethis_are_oasis_months(): void
    {
        foreach (TharadiCalendar::MONTHS as $index => $month) {
            $tickAtMonthStart = $index * TharadiCalendar::DAYS_PER_MONTH * TharadiCalendar::HOURS_PER_DAY;
            $season = TharadiCalendar::fromTick($tickAtMonthStart)->season;
            $expected = $index < 2 ? 'Oasis' : 'Sandstorm';
            $this->assertSame($expected, $season, "Month {$month['name']} season");
        }
    }

    public function test_year_rolls_over_after_a_full_year_of_days(): void
    {
        $tick = TharadiCalendar::DAYS_PER_YEAR * TharadiCalendar::HOURS_PER_DAY;
        $date = TharadiCalendar::fromTick($tick);

        $this->assertSame(2, $date->year);
        $this->assertSame(0, $date->monthIndex);
        $this->assertSame(1, $date->dayOfMonth);
    }
}
