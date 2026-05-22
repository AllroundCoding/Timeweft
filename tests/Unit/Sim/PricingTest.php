<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\Pricing;
use PHPUnit\Framework\TestCase;

/**
 * TWT-47: a good's local price responds to supply and demand — scarcity raises it, a glut lowers it,
 * and at the reference holding it trades at its base value. The signal that makes the economy
 * self-regulating rather than fixed-rate.
 */
class PricingTest extends TestCase
{
    public function test_the_reference_holding_trades_at_base_value(): void
    {
        // 5 units/head is the reference; at it, price is exactly the base value.
        $this->assertEqualsWithDelta(10.0, Pricing::localPrice(10.0, stock: 50.0, population: 10), 1e-9);
    }

    public function test_scarcity_raises_the_price_and_glut_lowers_it(): void
    {
        $scarce = Pricing::localPrice(10.0, stock: 10.0, population: 10);  // 1/head, well below reference
        $base = Pricing::localPrice(10.0, stock: 50.0, population: 10);    // 5/head, the reference
        $glut = Pricing::localPrice(10.0, stock: 200.0, population: 10);   // 20/head, far above

        $this->assertGreaterThan($base, $scarce, 'a short market is dear');
        $this->assertLessThan($base, $glut, 'a flush market is cheap');
    }

    public function test_the_price_is_clamped_at_both_extremes(): void
    {
        $famine = Pricing::localPrice(10.0, stock: 0.0, population: 10);     // nothing left
        $flooded = Pricing::localPrice(10.0, stock: 1_000_000.0, population: 10);

        $this->assertEqualsWithDelta(40.0, $famine, 1e-9, 'scarcity caps at 4x base');
        $this->assertEqualsWithDelta(2.5, $flooded, 1e-9, 'glut floors at 0.25x base');
    }

    public function test_with_no_one_to_trade_the_price_is_just_the_base(): void
    {
        $this->assertSame(10.0, Pricing::localPrice(10.0, stock: 0.0, population: 0));
    }
}
