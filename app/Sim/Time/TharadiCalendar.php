<?php

namespace App\Sim\Time;

/**
 * The Tharadi calendar (from the Vaeris canon): 2 seasons, 8 god-dedicated
 * months, a 6-day week. The simulation runs on a single canonical integer
 * clock (1 tick = 1 hour); this class projects a tick onto a readable date.
 *
 * Canon fixes the season/month/week structure. It does NOT specify days-per-month
 * or hours-per-day, so those are chosen defaults and easily changed.
 */
final class TharadiCalendar
{
    public const HOURS_PER_DAY = 24;   // not in canon — chosen default
    public const DAYS_PER_WEEK = 6;    // canon: 6-day week
    public const DAYS_PER_MONTH = 30;  // not in canon — chosen (5 weeks/month)
    public const MONTHS_PER_YEAR = 8;  // canon: 8 months
    public const DAYS_PER_YEAR = self::DAYS_PER_MONTH * self::MONTHS_PER_YEAR; // 240

    /**
     * Months in order from the new year (Naralis = start of Oasis).
     * Canon: exactly 2 months fall in Oasis and 6 in Sandstorm — so Kalimos's
     * "late Oasis" and Lunaris's "transition" flavor are folded into Sandstorm
     * to preserve the 2/6 split.
     */
    public const MONTHS = [
        ['name' => 'Naralis',  'season' => 'Oasis',     'god' => 'Nara'],
        ['name' => 'Jarethis', 'season' => 'Oasis',     'god' => 'Jarek'],
        ['name' => 'Kalimos',  'season' => 'Sandstorm', 'god' => 'Kalim'],
        ['name' => 'Lunaris',  'season' => 'Sandstorm', 'god' => 'Lunara'],
        ['name' => "Ra'anis",  'season' => 'Sandstorm', 'god' => "Ra'an"],
        ['name' => 'Varith',   'season' => 'Sandstorm', 'god' => 'Varis'],
        ['name' => 'Mirathis', 'season' => 'Sandstorm', 'god' => 'Mirah'],
        ['name' => 'Zarakon',  'season' => 'Sandstorm', 'god' => 'Zarak'],
    ];

    /** 6-day week, named for the six gods central to daily Tharadi life. */
    public const WEEKDAYS = ["Ra'ans", 'Naras', 'Mirahs', 'Zaraks', 'Kalims', 'Jareks'];

    public static function fromTick(int $tick): TharadiDate
    {
        $hour = $tick % self::HOURS_PER_DAY;
        $totalDays = intdiv($tick, self::HOURS_PER_DAY);

        $weekdayIndex = $totalDays % self::DAYS_PER_WEEK;
        $year = intdiv($totalDays, self::DAYS_PER_YEAR) + 1;
        $dayOfYear = $totalDays % self::DAYS_PER_YEAR;
        $monthIndex = intdiv($dayOfYear, self::DAYS_PER_MONTH);
        $dayOfMonth = ($dayOfYear % self::DAYS_PER_MONTH) + 1;

        $month = self::MONTHS[$monthIndex];

        return new TharadiDate(
            tick: $tick,
            year: $year,
            monthIndex: $monthIndex,
            monthName: $month['name'],
            season: $month['season'],
            patronGod: $month['god'],
            dayOfMonth: $dayOfMonth,
            weekdayIndex: $weekdayIndex,
            weekdayName: self::WEEKDAYS[$weekdayIndex],
            hour: $hour,
        );
    }
}
