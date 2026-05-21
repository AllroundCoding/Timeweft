<?php

namespace App\Sim\World;

use App\Sim\Support\Rng;
use App\Sim\Time\TharadiDate;

/**
 * Discrete shocks (design doc 06): once a year a bad roll brings a blight that
 * ruins the stores or a raid that claims lives. Sharp, occasional events —
 * distinct from the steady seasonal cycle — that stress-test the pressure→relief
 * loop and give the chronicle dramatic, non-cyclical beats.
 */
final class ShockEngine
{
    private const SHOCK_CHANCE_PER_YEAR = 0.08;

    private const FAMINE_GRANARY_LOSS = 0.7;

    private const RAID_CASUALTY_RATE = 0.15;

    private const PLAGUE_SICKNESS = 35.0; // sickness a plague inflicts on every soul at once

    public static function runDay(World $world, int $tick, TharadiDate $date): void
    {
        if ($date->monthIndex !== 0 || $date->dayOfMonth !== 1) {
            return; // roll once a year, at the turn of the year
        }

        $rng = $world->rng;
        if (! $rng->chance(self::SHOCK_CHANCE_PER_YEAR)) {
            return;
        }

        match ($rng->int(1, 3)) {
            1 => self::applyFamine($world, $tick, $date),
            2 => self::applyRaid($world, $tick, $date, $rng),
            default => self::applyPlague($world, $tick, $date),
        };
    }

    public static function applyPlague(World $world, int $tick, TharadiDate $date): void
    {
        $struck = 0;
        foreach ($world->livingAgents() as $agent) {
            $sickness = $agent->needs['sickness'] ?? null;
            if ($sickness !== null) {
                $sickness->value = min(100.0, $sickness->value + self::PLAGUE_SICKNESS);
                $struck++;
            }
        }
        if ($struck === 0) {
            return;
        }

        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — a plague sweeps through %s; the sick fill its homes.',
            $date->dayOfMonth, $date->monthName, $date->year, $world->village->name,
        ));
    }

    public static function applyFamine(World $world, int $tick, TharadiDate $date): void
    {
        $granary = $world->village->stockpile;
        $granary->withdraw('food', $granary->amount('food') * self::FAMINE_GRANARY_LOSS);
        $granary->withdraw('water', $granary->amount('water') * self::FAMINE_GRANARY_LOSS);

        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — a blight ruins much of the stores at %s.',
            $date->dayOfMonth, $date->monthName, $date->year, $world->village->name,
        ));
    }

    public static function applyRaid(World $world, int $tick, TharadiDate $date, Rng $rng): void
    {
        $pool = $world->livingAgents();
        $casualties = min(count($pool), (int) ceil(count($pool) * self::RAID_CASUALTY_RATE));
        if ($casualties === 0) {
            return;
        }

        for ($i = 0; $i < $casualties; $i++) {
            $index = $rng->int(0, count($pool) - 1);
            $victim = $pool[$index];
            array_splice($pool, $index, 1);

            $victim->alive = false;
            $victim->deathTick = $tick;
            if ($victim->partnerId !== null) {
                foreach ($world->village->agents as $other) {
                    if ($other->id === $victim->partnerId) {
                        $other->partnerId = null;
                    }
                }
                $victim->partnerId = null;
            }
        }

        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — raiders strike %s; %d souls are lost.',
            $date->dayOfMonth, $date->monthName, $date->year, $world->village->name, $casualties,
        ));
    }
}
