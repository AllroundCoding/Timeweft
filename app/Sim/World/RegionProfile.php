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
     */
    public function __construct(
        public readonly string $name,
        public readonly array $traitModifiers,
        public readonly array $categoricalOptions,
        public readonly array $yieldBySeason = [],
    ) {}

    public static function tharados(): self
    {
        return new self(
            name: 'Tharados',
            traitModifiers: [
                'constitution' => 8.0,  // resilient desert dwellers
                'senses' => 4.0,        // attuned to the open desert
            ],
            categoricalOptions: [
                'furColor' => ['sandy', 'golden', 'pale tan', 'dust-grey', 'ochre'],
            ],
            yieldBySeason: [
                'Oasis' => 1.5,      // the brief, fertile season
                'Sandstorm' => 0.5,  // the long, lean months
            ],
        );
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
