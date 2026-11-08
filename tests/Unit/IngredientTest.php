<?php

namespace Tests\Unit;

use App\Enums\IngredientUnit;
use App\Enums\RecipeMeasure;
use App\Enums\StockLevel;
use App\Models\Ingredient;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function test_a_weight_or_volume_unit_already_says_what_it_holds(): void
    {
        $this->assertSame(1000000, (new Ingredient(['unit' => IngredientUnit::Kilogram]))->baseThousandthsPerUnit());
        $this->assertSame(1000, (new Ingredient(['unit' => IngredientUnit::Gram]))->baseThousandthsPerUnit());
        $this->assertSame(1000000, (new Ingredient(['unit' => IngredientUnit::Liter]))->baseThousandthsPerUnit());
        $this->assertSame(IngredientUnit::Milliliter, (new Ingredient(['unit' => IngredientUnit::Liter]))->baseMeasure());
    }

    public function test_a_pack_holds_only_what_it_has_been_told(): void
    {
        $untold = new Ingredient(['unit' => IngredientUnit::Pack]);

        $this->assertNull($untold->baseThousandthsPerUnit());
        $this->assertSame([RecipeMeasure::Unit], $untold->measuresAvailable());

        $told = new Ingredient([
            'unit' => IngredientUnit::Pack,
            'content_per_unit' => '500',
            'content_measure' => IngredientUnit::Milliliter,
        ]);

        $this->assertSame(500000, $told->baseThousandthsPerUnit());
        $this->assertSame(IngredientUnit::Milliliter, $told->baseMeasure());
        $this->assertSame([RecipeMeasure::Unit, RecipeMeasure::Base], $told->measuresAvailable());
    }

    public function test_cups_and_spoons_need_a_cup_weight(): void
    {
        $flour = new Ingredient(['unit' => IngredientUnit::Kilogram, 'cup_weight' => '120']);

        $this->assertSame(RecipeMeasure::cases(), $flour->measuresAvailable());

        // A cup is 120 g, a tablespoon a sixteenth of that, a teaspoon a
        // forty-eighth — each as thousandths of a kilogram.
        $this->assertEqualsWithDelta(120.0, $flour->toStockThousandths(1000, RecipeMeasure::Cup), 0.0001);
        $this->assertEqualsWithDelta(7.5, $flour->toStockThousandths(1000, RecipeMeasure::Tablespoon), 0.0001);
        $this->assertEqualsWithDelta(2.5, $flour->toStockThousandths(1000, RecipeMeasure::Teaspoon), 0.0001);
        $this->assertEqualsWithDelta(250.0, $flour->toStockThousandths(250000, RecipeMeasure::Base), 0.0001);
        $this->assertEqualsWithDelta(3000.0, $flour->toStockThousandths(3000, RecipeMeasure::Unit), 0.0001);
    }

    public function test_a_measure_with_no_conversion_is_refused(): void
    {
        $eggs = new Ingredient(['name' => 'Eggs', 'unit' => IngredientUnit::Piece]);

        $this->expectException(RuntimeException::class);

        $eggs->toStockThousandths(1000, RecipeMeasure::Cup);
    }
}
