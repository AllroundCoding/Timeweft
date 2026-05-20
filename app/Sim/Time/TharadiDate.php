<?php

namespace App\Sim\Time;

/**
 * Immutable projection of a canonical tick onto the Tharadi calendar.
 * Produced by TharadiCalendar::fromTick(); never constructed directly by the sim.
 */
final class TharadiDate
{
    public function __construct(
        public readonly int $tick,
        public readonly int $year,          // 1-based
        public readonly int $monthIndex,    // 0..7
        public readonly string $monthName,
        public readonly string $season,     // Oasis | Sandstorm
        public readonly string $patronGod,
        public readonly int $dayOfMonth,    // 1-based
        public readonly int $weekdayIndex,  // 0..5
        public readonly string $weekdayName,
        public readonly int $hour,          // 0..23
    ) {}

    public function format(): string
    {
        return sprintf(
            '%d %s, Year %d — %s, %02d:00 (%s, of %s)',
            $this->dayOfMonth,
            $this->monthName,
            $this->year,
            $this->weekdayName,
            $this->hour,
            $this->season,
            $this->patronGod,
        );
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
