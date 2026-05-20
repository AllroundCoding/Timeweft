<?php

namespace App\Sim\World;

/** Regional flavor layered on top of a species' trait registry: numeric nudges + categorical vocabularies. */
final class RegionProfile
{
    /**
     * @param  array<string,float>  $traitModifiers  additive nudges to numeric traits at birth
     * @param  array<string,list<string>>  $categoricalOptions  trait => allowed values for that region
     */
    public function __construct(
        public readonly string $name,
        public readonly array $traitModifiers,
        public readonly array $categoricalOptions,
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
        );
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
