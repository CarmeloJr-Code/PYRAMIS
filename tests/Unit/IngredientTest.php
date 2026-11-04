<?php

namespace Tests\Unit;

use App\Enums\StockLevel;
use App\Models\Ingredient;
use PHPUnit\Framework\TestCase;

class IngredientTest extends TestCase
{
    public function test_stock_comes_from_the_aggregate_the_listing_already_selected(): void
    {
        // No query behind this one: putting one behind every row of a listing
        // is the defect the aggregate exists to avoid.
        $this->assertSame(10500, $this->ingredient('0', '10.5')->stockInThousandths());
    }

    public function test_an_ingredient_nothing_has_moved_has_no_stock(): void
    {
        // The aggregate is null rather than zero for an ingredient with an
        // empty ledger, which must still read as none rather than fall through
        // to a query.
        $this->assertSame(0, $this->ingredient('0', null)->stockInThousandths());
    }

    public function test_nothing_left_is_out_of_stock(): void
    {
        $this->assertTrue($this->ingredient('0', '0')->isOutOfStock());
        $this->assertFalse($this->ingredient('0', '0.001')->isOutOfStock());
    }

    public function test_a_ledger_that_has_gone_negative_still_reads_as_out(): void
    {
        $this->assertTrue($this->ingredient('0', '-2')->isOutOfStock());
    }

    public function test_stock_at_or_under_the_reorder_level_is_low(): void
    {
        $this->assertTrue($this->ingredient('5', '4.999')->isLowStock());
        $this->assertTrue($this->ingredient('5', '5')->isLowStock());
        $this->assertFalse($this->ingredient('5', '5.001')->isLowStock());
    }

    public function test_an_ingredient_with_no_reorder_level_set_never_reports_as_low(): void
    {
        // Otherwise every unconfigured ingredient would shout, and the count
        // of what needs ordering would mean nothing.
        $this->assertFalse($this->ingredient('0', '0')->isLowStock());
    }

    public function test_a_retired_ingredient_reads_as_not_stocked_whatever_it_holds(): void
    {
        $this->assertSame(StockLevel::NotStocked, $this->ingredient('5', '0', active: false)->stockLevel());
        $this->assertSame(StockLevel::NotStocked, $this->ingredient('5', '20', active: false)->stockLevel());
    }

    public function test_nothing_left_outranks_being_low(): void
    {
        $this->assertSame(StockLevel::OutOfStock, $this->ingredient('5', '0')->stockLevel());
        $this->assertSame(StockLevel::OutOfStock, $this->ingredient('0', '-2')->stockLevel());
    }

    public function test_stock_at_the_reorder_level_is_low_and_above_it_is_in_stock(): void
    {
        $this->assertSame(StockLevel::Low, $this->ingredient('5', '5')->stockLevel());
        $this->assertSame(StockLevel::InStock, $this->ingredient('5', '5.001')->stockLevel());
    }

    public function test_with_no_reorder_level_set_any_stock_is_in_stock(): void
    {
        $this->assertSame(StockLevel::InStock, $this->ingredient('0', '0.001')->stockLevel());
    }

    /**
     * An ingredient carrying the stock aggregate a listing would have selected.
     */
    private function ingredient(string $reorderLevel, ?string $stock, bool $active = true): Ingredient
    {
        $ingredient = new Ingredient(['reorder_level' => $reorderLevel]);

        $ingredient->setAttribute('is_active', $active);

        $ingredient->setAttribute('stock_sum', $stock);

        return $ingredient;
    }
}
