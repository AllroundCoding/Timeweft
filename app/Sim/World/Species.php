<?php

namespace App\Sim\World;

use App\Sim\Culture\Culture;
use App\Sim\Support\NameGenerator;
use App\Sim\Support\Rng;
use App\Sim\Traits\TraitDefinition;
use App\Sim\Traits\TraitRegistry;

/** A species template: a typed trait registry that a region then nudges. */
final class Species
{
    public function __construct(
        public readonly string $name,
        public readonly TraitRegistry $traits,
    ) {}

    public static function vulpini(): self
    {
        $traits = (new TraitRegistry)
            ->define(TraitDefinition::numeric('agility', 60.0, 90.0, mutation: 5.0))
            ->define(TraitDefinition::numeric('senses', 60.0, 92.0, mutation: 5.0))
            ->define(TraitDefinition::numeric('dexterity', 55.0, 85.0, mutation: 5.0))
            ->define(TraitDefinition::numeric('constitution', 40.0, 80.0, mutation: 5.0))
            ->define(TraitDefinition::numeric('sociability', 30.0, 90.0, mutation: 5.0))
            ->define(TraitDefinition::categorical('furColor'))
            ->define(TraitDefinition::numeric('heatTolerance', 60.0, 95.0, mutation: 4.0))
            ->define(TraitDefinition::numeric('generosity', 30.0, 70.0, mutation: 5.0))
            ->define(TraitDefinition::numeric('thrift', 30.0, 70.0, mutation: 5.0))
            // The Big Five personal layer (OCEAN) — extraversion is `sociability`, agreeableness
            // is `generosity`; these are the remaining three. Culture sets the mean, the
            // individual varies around it (design doc 11).
            ->define(TraitDefinition::numeric('openness', 30.0, 70.0, mutation: 6.0))
            ->define(TraitDefinition::numeric('conscientiousness', 30.0, 70.0, mutation: 6.0))
            ->define(TraitDefinition::numeric('neuroticism', 30.0, 70.0, mutation: 6.0));

        return new self('Vulpini', $traits);
    }

    /** Generate a fresh founder/agent from the registry + region (physical) and culture (dispositional) modifiers. */
    public function birth(
        int $id,
        int $birthTick,
        RegionProfile $region,
        Culture $culture,
        Rng $rng,
        NameGenerator $names,
    ): Agent {
        $traits = $this->traits->generate($region, $culture, $rng);

        return new Agent(
            id: $id,
            name: $names->name($rng->stream('name'), $culture->name),
            species: $this->name,
            region: $region->name,
            sex: $rng->chance(0.5) ? 'f' : 'm',
            birthTick: $birthTick,
            traits: $traits,
            needs: self::freshNeeds($rng),
        );
    }

    /** Produce a child whose traits are inherited from both parents per the registry. */
    public function breed(
        int $id,
        Agent $mother,
        Agent $father,
        int $birthTick,
        Rng $rng,
        NameGenerator $names,
        string $cultureName,
    ): Agent {
        $traits = $this->traits->inherit($mother, $father, $rng);

        $child = new Agent(
            id: $id,
            name: $names->name($rng->stream('name'), $cultureName),
            species: $this->name,
            region: $mother->region,
            sex: $rng->chance(0.5) ? 'f' : 'm',
            birthTick: $birthTick,
            traits: $traits,
            needs: self::freshNeeds($rng),
        );
        $child->parentIds = [$mother->id, $father->id];

        return $child;
    }

    /** @return array<string,Need> */
    private static function freshNeeds(Rng $rng): array
    {
        return [
            'hunger' => new Need('hunger', $rng->float(0, 20), 100.0 / 16.0),
            'energy' => new Need('energy', $rng->float(0, 15), 100.0 / 18.0),
            // Sickness is driven by conditions (HealthEngine) and shocks, not a self-rise, so 0/tick.
            'sickness' => new Need('sickness', 0.0, 0.0),
        ];
    }
}
