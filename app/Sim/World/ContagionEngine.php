<?php

namespace App\Sim\World;

use App\Sim\Time\TharadiDate;

/**
 * Disease as a *network* phenomenon (design docs 05 + 06; TWT-79). {@see HealthEngine} carries sickness
 * within a settlement and {@see ShockEngine}'s plague seeds an outbreak in one; this engine lets that
 * outbreak leave home. Each day contagion rides the contact between settlements into their neighbours —
 * with a lag, a step per day — so a plague becomes the Black Death on the sea lanes and the Silk Road
 * rather than a village-bound event, and how hard a settlement is hit tracks how connected it is.
 *
 * Two vectors carry it, both gated so a truly isolated settlement is spared:
 *   - trade routes: an established, well-trafficked route ({@see TradeEngine}, TWT-127) carries contagion
 *     regardless of distance — a port reached by a shipping lane catches the plague though it lies an
 *     ocean away;
 *   - proximity: adjacent settlements infect one another through incidental contact (the wind/water
 *     vector), fading to nothing past a contact range.
 * Migration carries it for free besides — a migrant physically takes their sickness to the destination.
 *
 * World-level and evaluated once a day, RNG-free like HealthEngine. It reads a *snapshot* of every
 * settlement's infectiousness taken before anyone is infected, so spread can't chain A→B→C inside a
 * single day — the lag is real, a hop per day. With fewer than two settlements there is no one to
 * infect, so it does nothing and draws no RNG, leaving the single-settlement run byte-identical.
 */
final class ContagionEngine
{
    /** Fraction of the sickness arriving over its links a settlement takes on per day. */
    private const INFECTIOUSNESS = 0.01;

    /** Map distance over which the proximity (wind/water) vector fades to nothing. */
    private const CONTACT_RANGE = 120.0;

    /** A fully-mature trade route's transmission strength — a lane carries contagion regardless of distance. */
    private const ROUTE_WEIGHT = 0.8;

    /** Mean sickness (0..100) at which a settlement is gripped by an outbreak worth chronicling. */
    private const OUTBREAK_THRESHOLD = 40.0;

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if (count($world->villages) < 2) {
            return; // no one to infect
        }

        // Snapshot each settlement's infectious pressure (its mean sickness) before anyone is infected,
        // so the day's spread reads only yesterday's state — A→B→C takes three days, not one tick.
        $sickness = [];
        foreach ($world->villages as $i => $village) {
            $sickness[$i] = self::meanSickness($village);
        }

        foreach ($world->villages as $i => $target) {
            $living = $target->livingAgents();
            if ($living === []) {
                continue;
            }

            $incoming = 0.0;
            $source = null;
            $strongest = 0.0;
            foreach ($world->villages as $j => $neighbour) {
                if ($i === $j || $sickness[$j] <= 0.0) {
                    continue;
                }
                $carried = self::transmission($world, $neighbour, $target) * $sickness[$j];
                $incoming += $carried;
                if ($carried > $strongest) {
                    $strongest = $carried;
                    $source = $neighbour;
                }
            }
            if ($incoming <= 0.0) {
                continue; // isolated: out of contact range and on no route
            }

            $delta = self::INFECTIOUSNESS * $incoming;
            foreach ($living as $agent) {
                $need = $agent->needs['sickness'] ?? null;
                if ($need !== null) {
                    $need->value = max(0.0, min(100.0, $need->value + $delta));
                }
            }

            self::latchOutbreak($world, $target, $source, $tick, $date);
        }
    }

    /**
     * Latch a settlement's outbreak state, chronicling the day a neighbour's contagion first tips it over
     * the threshold (so deaths there can cite where the plague came from, doc 09) and clearing once it has
     * clearly passed — leaving room for a later wave to be marked again.
     */
    private static function latchOutbreak(World $world, Village $village, ?Village $source, int $tick, TharadiDate $date): void
    {
        $mean = self::meanSickness($village);
        if ($mean >= self::OUTBREAK_THRESHOLD) {
            if (! $village->inOutbreak && $source !== null) {
                $struck = array_map(static fn (Agent $a): int => $a->id, $village->livingAgents());
                $event = $world->chronicle->record($tick, sprintf(
                    '%d %s, Year %d — the sickness reaches %s, carried from %s.',
                    $date->dayOfMonth, $date->monthName, $date->year, $village->name, $source->name,
                ), 'contagion', $struck, [], ['plague']);
                $village->lastPlagueEventId = $event->id;
            }
            $village->inOutbreak = true;
        } elseif ($mean < self::OUTBREAK_THRESHOLD / 2.0) {
            $village->inOutbreak = false;
        }
    }

    /**
     * The share of a source's sickness that reaches a target in a day, 0 when truly isolated: a trade
     * route carries it across any distance, proximity carries it to near neighbours and fades to nothing
     * past the contact range. The two add, so a near *and* route-linked settlement is the most exposed.
     */
    private static function transmission(World $world, Village $source, Village $target): float
    {
        $distance = $source->distanceTo($target);
        $proximity = max(0.0, 1.0 - $distance / self::CONTACT_RANGE);
        $route = self::ROUTE_WEIGHT * TradeEngine::routeMaturity($world, $source, $target);

        return $proximity + $route;
    }

    /** A settlement's infectious pressure: the mean sickness of its living members (0..100). */
    private static function meanSickness(Village $village): float
    {
        $total = 0.0;
        $counted = 0;
        foreach ($village->livingAgents() as $agent) {
            $need = $agent->needs['sickness'] ?? null;
            if ($need !== null) {
                $total += $need->value;
                $counted++;
            }
        }

        return $counted > 0 ? $total / $counted : 0.0;
    }
}
