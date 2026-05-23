<?php

namespace App\Sim\World;

use App\Sim\Time\TharadiDate;

/**
 * When a settlement is failing, someone goes for help (design doc 05/06; TWT-184). A village dying
 * out is realistic *without* aid — but it shouldn't dwindle in silence. Sustained famine is a distress
 * signal: amicable neighbours with food to spare answer it with **gratis relief** (mutual aid,
 * Sahlins — TWT-85; the free, crisis-driven counterpart to paid trade, and unlike trade it digs deeper
 * into the donor's stores and ignores distance, because a plea for help travels). Sworn enemies send
 * nothing (relations gate it, TWT-125). And a settlement that empties anyway — to death or exodus — is
 * mourned with a collapse beat, not a quiet fade.
 *
 * World-level, evaluated yearly, deterministic and RNG-free. Below two settlements no aid can come,
 * but a lone settlement can still collapse — which only fires when it actually empties, so a viable
 * canonical run never trips it and stays byte-identical.
 */
final class DistressEngine
{
    /** Consecutive famine years that mark a settlement as in distress — past coping, sending for help. */
    private const DISTRESS_YEARS = 2;

    /** Days of food per head an allied, kindred donor keeps back in a crisis — the most cohesive dig deepest. */
    private const AID_KEEP_DAYS_MIN = 4.0;

    /** Days a barely-amicable donor keeps back — a lukewarm neighbour parts with little (TWT-52). */
    private const AID_KEEP_DAYS_MAX = 12.0;

    /** Relief lifts a stricken settlement up to this many days of food per head. */
    private const RELIEF_TARGET_DAYS = 5.0;

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // reckoned at the turn of the year
        }

        foreach ($world->villages as $village) {
            $village->famineYears = $village->inFamine ? $village->famineYears + 1 : 0;
        }
        foreach ($world->villages as $village) {
            if ($village->famineYears >= self::DISTRESS_YEARS) {
                self::sendForHelp($world, $village, $tick, $date);
            }
            self::mournIfCollapsed($world, $village, $tick, $date);
        }
    }

    /** Draw gratis relief from amicable neighbours with food to spare, up to a survivable ration. */
    private static function sendForHelp(World $world, Village $stricken, int $tick, TharadiDate $date): void
    {
        $population = count($stricken->livingAgents());
        if ($population === 0) {
            return; // no one left to save
        }
        $need = (self::RELIEF_TARGET_DAYS * $population) - $stricken->stockpile->amount('food');
        if ($need <= 0.0) {
            return; // already fed
        }

        $relief = 0.0;
        foreach ($world->villages as $donor) {
            if ($donor === $stricken || RelationsEngine::hostile($world, $donor, $stricken)) {
                continue; // enemies let them starve
            }
            $donorPop = count($donor->livingAgents());
            if ($donorPop === 0) {
                continue;
            }
            // How deep a donor digs scales with its cohesion to the stricken (TWT-52): an allied, kindred
            // neighbour keeps little back, a lukewarm one keeps more. At neutral standing this is the old
            // fixed buffer, so a viable run is unchanged.
            $cohesion = RelationsEngine::cohesion($world, $donor, $stricken);
            $keepDays = self::AID_KEEP_DAYS_MAX - (self::AID_KEEP_DAYS_MAX - self::AID_KEEP_DAYS_MIN) * $cohesion;
            $spare = $donor->stockpile->amount('food') - $keepDays * $donorPop;
            if ($spare <= 0.0) {
                continue;
            }
            $given = min($spare, $need - $relief);
            if ($given <= 0.0) {
                break; // need met
            }
            $donor->stockpile->withdraw('food', $given);
            $stricken->stockpile->add('food', $given);
            $relief += $given;
        }

        if ($relief > 0.0) {
            $world->chronicle->record($tick, sprintf(
                '%d %s, Year %d — relief reaches stricken %s; neighbours share against the famine.',
                $date->dayOfMonth, $date->monthName, $date->year, $stricken->name,
            ), 'aid', [], [], ['aid']);
        }
    }

    /** A settlement that has emptied — to death or exodus — is mourned once. */
    private static function mournIfCollapsed(World $world, Village $village, int $tick, TharadiDate $date): void
    {
        if ($village->collapsed || $village->agents === [] || $village->livingAgents() !== []) {
            return; // never settled, still inhabited, or already mourned
        }

        $village->collapsed = true;
        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — %s falls silent; the last of its people are gone, and the settlement is no more.',
            $date->dayOfMonth, $date->monthName, $date->year, $village->name,
        ), 'collapse', [], [], ['collapse']);
    }
}
