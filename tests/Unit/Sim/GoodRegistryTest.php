<?php

namespace Tests\Unit\Sim;

use App\Sim\Economy\Good;
use App\Sim\Economy\GoodRegistry;
use PHPUnit\Framework\TestCase;

class GoodRegistryTest extends TestCase
{
    public function test_a_good_carries_a_stat_vector(): void
    {
        $dates = new Good('dates', nutrition: 70.0, value: 30.0, perishability: 40.0);

        $this->assertSame('dates', $dates->name);
        $this->assertSame(70.0, $dates->nutrition);
        $this->assertSame(30.0, $dates->value);
        $this->assertSame(40.0, $dates->perishability);
    }

    public function test_the_registry_defines_and_looks_up_goods(): void
    {
        $registry = (new GoodRegistry)->define(new Good('grain', nutrition: 60.0, value: 15.0, perishability: 10.0));

        $this->assertTrue($registry->has('grain'));
        $this->assertFalse($registry->has('silk'));
        $this->assertSame(60.0, $registry->get('grain')?->nutrition);
        $this->assertNull($registry->get('silk'));
    }

    public function test_the_tharadi_basket_is_a_catalog_of_typed_goods(): void
    {
        $basket = GoodRegistry::tharados();

        $this->assertSame(['water', 'grain', 'dates', 'goat meat'], array_keys($basket->all()));
        // Meat is the most nutritious and most perishable; water the least of both.
        $this->assertGreaterThan($basket->get('grain')->nutrition, $basket->get('goat meat')->nutrition);
        $this->assertGreaterThan($basket->get('grain')->perishability, $basket->get('goat meat')->perishability);
    }
}
