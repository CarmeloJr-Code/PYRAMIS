<?php

namespace Tests\Unit;

use App\Enums\StockLevel;
use PHPUnit\Framework\TestCase;

class StockLevelTest extends TestCase
{
    public function test_each_level_has_one_label_and_one_colour_on_every_screen(): void
    {
        $this->assertSame(['Not stocked', 'zinc'], [StockLevel::NotStocked->label(), StockLevel::NotStocked->color()]);
        $this->assertSame(['Out of stock', 'red'], [StockLevel::OutOfStock->label(), StockLevel::OutOfStock->color()]);
        $this->assertSame(['Low', 'amber'], [StockLevel::Low->label(), StockLevel::Low->color()]);
        $this->assertSame(['In stock', 'green'], [StockLevel::InStock->label(), StockLevel::InStock->color()]);
    }

    public function test_only_the_levels_that_need_ordering_carry_a_warning_icon(): void
    {
        // So the two that need action still stand out without colour.
        $this->assertSame('exclamation-triangle', StockLevel::OutOfStock->icon());
        $this->assertSame('exclamation-triangle', StockLevel::Low->icon());
        $this->assertNull(StockLevel::InStock->icon());
        $this->assertNull(StockLevel::NotStocked->icon());
    }
}
