<?php

namespace App\Sim\World;

use App\Sim\Support\Rng;

/** Regional overrides layered on top of a species template (composable traits). */
final class RegionProfile
{
    /**
     * @param array<string,float> $traitModifiers additive nudges to species base traits
     * @param list<string> $furPalette
     */
    public function __construct(
        public readonly string $name,
        public readonly array $traitModifiers,
        public readonly array $furPalette,
    ) {}

    public static function tharados(): self
    {
        return new self(
            name: 'Tharados',
            traitModifiers: [
                'constitution' => 8.0,  // resilient desert dwellers
                'senses' => 4.0,        // attuned to the open desert
            ],
            furPalette: ['sandy', 'golden', 'pale tan', 'dust-grey', 'ochre'],
        );
    }

    public function traitModifier(string $key): float
    {
        return $this->traitModifiers[$key] ?? 0.0;
    }

    /**
     * Region-specific derived traits.
     *
     * @return array<string,float|string>
     */
    public function extraTraits(Rng $rng): array
    {
        return [
            'heatTolerance' => round($rng->float(60, 95), 1),
        ];
    }
}
