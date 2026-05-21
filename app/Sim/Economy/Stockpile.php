<?php

namespace App\Sim\Economy;

/**
 * A bag of resources (food, water, money…) held by an agent or a settlement.
 * Quantities never go negative; withdraw() takes only what's there and reports how
 * much it actually got, so callers can detect a shortfall. This is the substrate the
 * production/consumption model, dynamic carrying capacity, and money build on.
 */
final class Stockpile
{
    /** @param array<string,float> $resources */
    public function __construct(private array $resources = []) {}

    public function amount(string $resource): float
    {
        return $this->resources[$resource] ?? 0.0;
    }

    public function add(string $resource, float $quantity): void
    {
        $this->resources[$resource] = $this->amount($resource) + max(0.0, $quantity);
    }

    /** Take up to $quantity; returns the amount actually withdrawn (a shortfall leaves the rest unmet). */
    public function withdraw(string $resource, float $quantity): float
    {
        $taken = min($this->amount($resource), max(0.0, $quantity));
        $this->resources[$resource] = $this->amount($resource) - $taken;

        return $taken;
    }

    public function has(string $resource, float $quantity): bool
    {
        return $this->amount($resource) >= $quantity;
    }

    /** @return array<string,float> */
    public function all(): array
    {
        return $this->resources;
    }
}
