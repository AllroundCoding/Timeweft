<?php

namespace App\Sim\Economy;

/** The recipes a settlement knows how to cook from its goods. */
final class RecipeBook
{
    /** @var list<Recipe> */
    private array $recipes = [];

    public function add(Recipe $recipe): self
    {
        $this->recipes[] = $recipe;

        return $this;
    }

    /** @return list<Recipe> */
    public function all(): array
    {
        return $this->recipes;
    }

    /** The Tharadi kitchen — what the oasis cooks from its basket. */
    public static function tharados(): self
    {
        return (new self)
            ->add(new Recipe('date-and-grain porridge', ['grain' => 2.0, 'dates' => 1.0]))
            ->add(new Recipe('spiced goat stew', ['goat meat' => 1.0, 'grain' => 1.0, 'dates' => 1.0]));
    }
}
