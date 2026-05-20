<?php

namespace App\Sim\Traits;

use App\Sim\Support\Rng;
use App\Sim\World\Agent;
use App\Sim\World\RegionProfile;

/**
 * The single source of truth for what traits an agent has and how they are
 * generated and inherited. Definitions are walked in registration order, so that
 * order is part of the seeded contract — reordering changes every seeded run.
 */
final class TraitRegistry
{
    /** @var array<string,TraitDefinition> */
    private array $definitions = [];

    public function define(TraitDefinition $definition): self
    {
        $this->definitions[$definition->key] = $definition;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * A fresh trait bag for a newborn: numeric traits drawn in range and nudged by
     * region modifiers, categorical traits picked from the region's options.
     *
     * @return array<string,float|string>
     */
    public function generate(RegionProfile $region, Rng $rng): array
    {
        $traits = [];
        foreach ($this->definitions as $def) {
            $traits[$def->key] = $def->type === TraitType::Numeric
                ? self::bounded($rng->float($def->min, $def->max) + $region->traitModifier($def->key))
                : $rng->pick($region->optionsFor($def->key));
        }

        return $traits;
    }

    /**
     * A trait bag inherited from two parents: numeric traits average + mutate,
     * categorical traits take one parent's value.
     *
     * @return array<string,float|string>
     */
    public function inherit(Agent $mother, Agent $father, Rng $rng): array
    {
        $traits = [];
        foreach ($this->definitions as $def) {
            if ($def->type === TraitType::Numeric) {
                $average = ((float) $mother->trait($def->key) + (float) $father->trait($def->key)) / 2.0;
                $traits[$def->key] = self::bounded($average + $rng->float(-$def->mutation, $def->mutation));
            } else {
                $traits[$def->key] = $rng->chance(0.5) ? $mother->trait($def->key) : $father->trait($def->key);
            }
        }

        return $traits;
    }

    private static function bounded(float $value): float
    {
        return round(max(0.0, min(100.0, $value)), 1);
    }
}
