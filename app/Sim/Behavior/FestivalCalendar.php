<?php

namespace App\Sim\Behavior;

use App\Sim\Time\TharadiDate;

/**
 * Calendar-pinned cultural events. The calendar itself drives behavior — in
 * Vaeris canon, months and days are god-dedicated and trigger observances.
 * Phase 0 pins one: the new-year Renewal of Nara.
 */
final class FestivalCalendar
{
    public static function on(TharadiDate $date): ?string
    {
        // Naralis 1 — the new year, the goddess Nara's blessing.
        if ($date->monthIndex === 0 && $date->dayOfMonth === 1) {
            return 'Renewal of Nara';
        }

        return null;
    }
}
