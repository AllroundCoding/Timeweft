<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\Stockpile;
use PHPUnit\Framework\TestCase;

class StockpileTest extends TestCase
{
    public function test_add_and_amount(): void
    {
        $stockpile = new Stockpile;
        $stockpile->add('food', 10.0);
        $stockpile->add('food', 5.0);

        $this->assertEqualsWithDelta(15.0, $stockpile->amount('food'), 1e-9);
        $this->assertSame(0.0, $stockpile->amount('water'));
    }

    public function test_withdraw_returns_what_was_taken_and_never_goes_negative(): void
    {
        $stockpile = new Stockpile(['food' => 8.0]);

        $this->assertEqualsWithDelta(8.0, $stockpile->withdraw('food', 20.0), 1e-9);
        $this->assertSame(0.0, $stockpile->amount('food'));
    }

    public function test_partial_withdraw_leaves_the_remainder(): void
    {
        $stockpile = new Stockpile(['water' => 10.0]);

        $this->assertEqualsWithDelta(3.0, $stockpile->withdraw('water', 3.0), 1e-9);
        $this->assertEqualsWithDelta(7.0, $stockpile->amount('water'), 1e-9);
    }

    public function test_has_checks_sufficiency(): void
    {
        $stockpile = new Stockpile(['money' => 5.0]);

        $this->assertTrue($stockpile->has('money', 5.0));
        $this->assertFalse($stockpile->has('money', 5.01));
    }
}
