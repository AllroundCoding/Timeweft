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

        // Draw the shock unconditionally; an edit may suppress its *effect*, but never its RNG draws,
        // so a counterfactual replay stays aligned with the true history except where the edit bites.
        $type = $rng->int(1, 3);
        $name = match ($type) {
            1 => 'blight',
            2 => 'raid',
            default => 'plague',
        };
        $suppress = $world->intervention?->suppressesShock($date->year, $name) ?? false;

        match ($type) {
            1 => self::applyFamine($world, $tick, $date, $suppress),
            2 => self::applyRaid($world, $tick, $date, $rng, $suppress),
            default => self::applyPlague($world, $tick, $date, $suppress),
        };
    }

    public static function applyPlague(World $world, int $tick, TharadiDate $date, bool $suppress = false): void
    {
        if ($suppress) {
            return;
        }

        $struck = [];
        foreach ($world->livingAgents() as $agent) {
            $sickness = $agent->needs['sickness'] ?? null;
            if ($sickness !== null) {
                $sickness->value = min(100.0, $sickness->value + self::PLAGUE_SICKNESS);
                $struck[] = $agent->id;
            }
        }
        if ($struck === []) {
            return;
        }

        $event = $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — a plague sweeps through %s; the sick fill its homes.',
            $date->dayOfMonth, $date->monthName, $date->year, $world->village->name,
        ), 'shock-plague', $struck);
        $world->village->lastPlagueEventId = $event->id;
    }

    public static function applyFamine(World $world, int $tick, TharadiDate $date, bool $suppress = false): void
    {
        if ($suppress) {
            return;
        }
        $granary = $world->village->stockpile;
        $granary->withdraw('food', $granary->amount('food') * self::FAMINE_GRANARY_LOSS);
        $granary->withdraw('water', $granary->amount('water') * self::FAMINE_GRANARY_LOSS);

        $event = $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — a blight ruins much of the stores at %s.',
            $date->dayOfMonth, $date->monthName, $date->year, $world->village->name,
        ), 'shock-blight', [], [], ['blight']);
        $world->village->lastBlightEventId = $event->id;
        $world->village->lastBlightYear = $date->year;
    }

    public static function applyRaid(World $world, int $tick, TharadiDate $date, Rng $rng, bool $suppress = false): void
    {
        $pool = $world->livingAgents();
        $casualties = min(count($pool), (int) ceil(count($pool) * self::RAID_CASUALTY_RATE));
        if ($casualties === 0) {
            return;
        }

        $fallen = [];
        for ($i = 0; $i < $casualties; $i++) {
            // Draw the victim even when suppressed (keeps the seeded stream aligned); only the death is undone.
            $index = $rng->int(0, count($pool) - 1);
            $victim = $pool[$index];
            array_splice($pool, $index, 1);
            if ($suppress) {
                continue;
            }

            $victim->alive = false;
            $victim->deathTick = $tick;
            $fallen[] = $victim->id;
            if ($victim->partnerId !== null) {
                foreach ($world->village->agents as $other) {
                    if ($other->id === $victim->partnerId) {
                        $other->partnerId = null;
                    }
                }
                $victim->partnerId = null;
            }
        }

        if ($suppress) {
            return;
        }

        $world->chronicle->record($tick, sprintf(
            '%d %s, Year %d — raiders strike %s; %d souls are lost.',
            $date->dayOfMonth, $date->monthName, $date->year, $world->village->name, $casualties,
        ), 'shock-raid', $fallen, [], ['raid']);
    }
}
