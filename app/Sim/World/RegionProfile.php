<?php

namespace App\Sim\World;

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
