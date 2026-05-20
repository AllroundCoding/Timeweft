<?php

namespace App\Sim\World;

use App\Sim\Support\Rng;
use App\Sim\Support\TharadiNameGenerator;

/** A species template: base trait ranges that a region then nudges. */
final class Species
{
    /** @param array<string,array{0:float,1:float}> $traitRanges trait => [min, max] */
    public function __construct(
        public readonly string $name,
        public readonly array $traitRanges,
    ) {}

    public static function vulpini(): self
    {
        return new self('Vulpini', [
            'agility' => [60.0, 90.0],
            'senses' => [60.0, 92.0],
            'dexterity' => [55.0, 85.0],
            'constitution' => [40.0, 80.0],
            'sociability' => [30.0, 90.0],
        ]);
    }

    public function birth(
        int $id,
        int $birthTick,
        RegionProfile $region,
        Rng $rng,
        TharadiNameGenerator $names,
    ): Agent {
        $traits = [];
        foreach ($this->traitRanges as $key => [$min, $max]) {
            $value = $rng->float($min, $max) + $region->traitModifier($key);
            $traits[$key] = round(max(0.0, min(100.0, $value)), 1);
        }
        $traits['furColor'] = $rng->pick($region->furPalette);
        $traits = array_merge($traits, $region->extraTraits($rng));

        $needs = [
            'hunger' => new Need('hunger', $rng->float(0, 20), 100.0 / 16.0),
            'energy' => new Need('energy', $rng->float(0, 15), 100.0 / 18.0),
        ];

        return new Agent(
            id: $id,
            name: $names->name(),
            species: $this->name,
            region: $region->name,
            sex: $rng->chance(0.5) ? 'f' : 'm',
            birthTick: $birthTick,
            traits: $traits,
            needs: $needs,
        );
    }
}
