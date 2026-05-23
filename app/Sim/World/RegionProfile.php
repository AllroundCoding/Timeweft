<?php

namespace App\Sim\World;

use App\Sim\Time\TharadiCalendar;

/** Regional flavor layered on top of a species' trait registry: numeric nudges + categorical vocabularies. */
final class RegionProfile
{
    /**
     * @param  array<string,float>  $traitModifiers  additive nudges to numeric traits at birth
     * @param  array<string,list<string>>  $categoricalOptions  trait => allowed values for that region
     * @param  array<string,float>  $yieldBySeason  food-yield multiplier per season (1.0 = baseline)
     * @param  array<string,float>  $basket  food good => per-adult daily yield (the diet this land grows)
     * @param  list<string>  $resources  the region's notable trade goods (its specialties)
     * @param  float  $landTenureConcentration  0 (dispersed/mobile) .. 1 (concentrated/owned) — how monopolizable the productive base is; drives hierarchy
     * @param  string  $cultureName  the demonym of this region's people (its naming style); empty falls back to the region name, never a hardcoded canon string
     */
    public function __construct(
        public readonly string $name,
        public readonly array $traitModifiers,
        public readonly array $categoricalOptions,
        public readonly array $yieldBySeason = [],
        public readonly array $basket = [],
        public readonly array $resources = [],
        public readonly float $landTenureConcentration = 0.5,
        public readonly string $cultureName = '',
    ) {}

    public static function tharados(): self
    {
        return RegionArchetype::desert()->toRegionProfile();
    }

    /** The basket of foodstuffs this region grows: good name => per-adult daily yield. */
    /** @return array<string,float> */
    public function basket(): array
    {
        return $this->basket;
    }

    /** The region's notable trade goods — what it produces in surplus and can offer elsewhere. */
    /** @return list<string> */
    public function resources(): array
    {
        return $this->resources;
    }

    /** How monopolizable the productive base is (0 dispersed .. 1 concentrated) — the driver of hierarchy. */
    public function landTenureConcentration(): float
    {
        return $this->landTenureConcentration;
    }

    /** The demonym of this region's people — its culture/naming style. Falls back to the region's own name. */
    public function cultureName(): string
    {
        return $this->cultureName !== '' ? $this->cultureName : $this->name;
    }

    /** Food-yield multiplier for a season (1.0 if the region defines none). */
    public function yieldMultiplier(string $season): float
    {
        return $this->yieldBySeason[$season] ?? 1.0;
    }

    /** Year-round average yield multiplier, weighted by how many months fall in each season. */
    public function averageYield(): float
    {
        $sum = 0.0;
        foreach (TharadiCalendar::MONTHS as $month) {
            $sum += $this->yieldMultiplier($month['season']);
        }

        return $sum / count(TharadiCalendar::MONTHS);
    }

    /** How lean the land is: 0 (abundant) .. 1 (harsh), from its average yield. */
    public function scarcity(): float
    {
        $abundance = max(0.0, min(1.0, $this->averageYield() - 0.5));

        return 1.0 - $abundance;
    }

    /** How much yield swings across the year: 0 (stable) .. 1 (wild), from the spread of seasonal yields. */
    public function seasonalVolatility(): float
    {
        $values = array_values($this->yieldBySeason);
        if ($values === []) {
            return 0.0;
        }

        $max = max($values);
        $min = min($values);

        return ($max + $min) > 0.0 ? ($max - $min) / ($max + $min) : 0.0;
    }

    public function traitModifier(string $key): float
    {
        return $this->traitModifiers[$key] ?? 0.0;
    }

    /** @return list<string> */
    public function optionsFor(string $key): array
    {
        return $this->categoricalOptions[$key] ?? [];
    }
}
