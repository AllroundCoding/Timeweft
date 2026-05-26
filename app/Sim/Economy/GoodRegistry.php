<?php

namespace App\Sim\Economy;

/**
 * The catalog of goods a world knows about — each a name → stat vector. The single source of
 * truth for what a good *is*, the way the trait registry is for agent traits. A region's basket
 * is generated from its materials later; for now the Tharadi oasis basket is hand-seeded.
 */
final class GoodRegistry
{
    /** @var array<string,Good> */
    private array $goods = [];

    public function define(Good $good): self
    {
        $this->goods[$good->name] = $good;

        return $this;
    }

    public function get(string $name): ?Good
    {
        return $this->goods[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->goods[$name]);
    }

    /** @return array<string,Good> */
    public function all(): array
    {
        return $this->goods;
    }

    /** What the Tharadi oasis can yield — staples, energy-dense fruit, and herd meat. */
    public static function tharados(): self
    {
        return (new self)
            ->define(new Good('water', nutrition: 5.0, value: 15.0, perishability: 10.0))
            ->define(new Good('grain', nutrition: 60.0, value: 25.0, perishability: 10.0))
            ->define(new Good('dates', nutrition: 70.0, value: 30.0, perishability: 40.0))
            ->define(new Good('goat meat', nutrition: 85.0, value: 50.0, perishability: 85.0));
    }

    public static function aetheria(): self
    {
        return (new self)
            ->define(new Good('water', nutrition: 5.0, value: 2.0, perishability: 10.0))
            ->define(new Good('grain', nutrition: 60.0, value: 10.0, perishability: 10.0))
            ->define(new Good('wood', nutrition: 0, value: 50, perishability: 0.0))
            ->define(new Good('wool', nutrition: 0, value: 70, perishability: 0.0))
            ->define(new Good('fruit', nutrition: 55.0, value: 25, perishability: 50.0))
            ->define(new Good('herbs', nutrition: 20.0, value: 35.0, perishability: 60.0))
            ->define(new Good('eggs', nutrition: 40.0, value: 40.0, perishability: 60.0))
            ->define(new Good('chicken meat', nutrition: 65.0, value: 40.0, perishability: 85.0));
    }
}
