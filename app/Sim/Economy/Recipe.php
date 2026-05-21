<?php

namespace App\Sim\Economy;

/**
 * A recipe combines base goods into a meal whose nutrition exceeds its raw ingredients — a
 * balanced diet beats raw grain (design doc 12). The synergy factor is "the whole is more than
 * the sum of its parts": a well-composed meal out-nourishes even its best single ingredient.
 */
final class Recipe
{
    /** @param array<string,float> $ingredients good name => amount */
    public function __construct(
        public readonly string $name,
        public readonly array $ingredients,
        public readonly float $synergy = 1.25,
    ) {}

    /** The meal this recipe yields: synergy applied to the amount-weighted average of its ingredients. */
    public function meal(GoodRegistry $goods): Good
    {
        $total = array_sum($this->ingredients);
        $nutrition = 0.0;
        $value = 0.0;
        $perishability = 0.0;

        foreach ($this->ingredients as $name => $amount) {
            $good = $goods->get($name);
            if ($good === null) {
                continue;
            }
            $nutrition += $good->nutrition * $amount;
            $value += $good->value * $amount;
            $perishability = max($perishability, $good->perishability);
        }

        return new Good(
            name: $this->name,
            nutrition: $total > 0.0 ? min(100.0, $nutrition / $total * $this->synergy) : 0.0,
            value: $total > 0.0 ? $value / $total : 0.0,
            perishability: min(100.0, $perishability + 10.0), // cooked food keeps a little less well
        );
    }

    /** Cook the meal if the pantry holds every ingredient: consume them and return the meal, else null. */
    public function cook(Stockpile $pantry, GoodRegistry $goods): ?Good
    {
        foreach ($this->ingredients as $name => $amount) {
            if (! $pantry->has($name, $amount)) {
                return null;
            }
        }
        foreach ($this->ingredients as $name => $amount) {
            $pantry->withdraw($name, $amount);
        }

        return $this->meal($goods);
    }
}
