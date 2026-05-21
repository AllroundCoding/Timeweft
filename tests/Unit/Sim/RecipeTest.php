<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\GoodRegistry;
use App\Sim\Economy\Recipe;
use App\Sim\Economy\RecipeBook;
use App\Sim\Economy\Stockpile;
use PHPUnit\Framework\TestCase;

class RecipeTest extends TestCase
{
    public function test_a_meal_out_nourishes_its_best_raw_ingredient(): void
    {
        $goods = GoodRegistry::tharados(); // grain 60, dates 70
        $porridge = new Recipe('porridge', ['grain' => 2.0, 'dates' => 1.0]);

        $meal = $porridge->meal($goods);

        // Synergy lifts the meal above both the weighted average and the best single ingredient.
        $this->assertGreaterThan(70.0, $meal->nutrition); // beats raw dates (70)
        $this->assertLessThanOrEqual(100.0, $meal->nutrition);
    }

    public function test_cooking_consumes_the_ingredients_and_returns_the_meal(): void
    {
        $goods = GoodRegistry::tharados();
        $pantry = new Stockpile(['grain' => 5.0, 'dates' => 3.0]);
        $porridge = new Recipe('porridge', ['grain' => 2.0, 'dates' => 1.0]);

        $meal = $porridge->cook($pantry, $goods);

        $this->assertNotNull($meal);
        $this->assertSame('porridge', $meal->name);
        $this->assertSame(3.0, $pantry->amount('grain')); // 5 − 2
        $this->assertSame(2.0, $pantry->amount('dates')); // 3 − 1
    }

    public function test_cooking_fails_without_the_ingredients_and_leaves_the_pantry_intact(): void
    {
        $goods = GoodRegistry::tharados();
        $pantry = new Stockpile(['grain' => 1.0]); // not enough grain, no dates
        $porridge = new Recipe('porridge', ['grain' => 2.0, 'dates' => 1.0]);

        $this->assertNull($porridge->cook($pantry, $goods));
        $this->assertSame(1.0, $pantry->amount('grain')); // untouched
    }

    public function test_the_tharadi_kitchen_offers_recipes(): void
    {
        $this->assertNotEmpty(RecipeBook::tharados()->all());
    }
}
