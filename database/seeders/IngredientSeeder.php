<?php

namespace Database\Seeders;

use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class IngredientSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the raw materials the bakery holds.
     *
     * Taken from the ingredients named in the recipes the business supplied
     * (docs/reference/recipes/), stocked in purchasing units rather than the
     * cooking measures the recipes call for. No reorder level is set: those are
     * the business's own thresholds, so the Administrator sets them at
     * /employee/inventory rather than having invented figures seeded.
     *
     * No opening stock either — a quantity only exists here because a receipt
     * was recorded for it.
     */
    public function run(): void
    {
        $ingredients = [
            ['Purple Yam Dry Premix', IngredientUnit::Pack],
            ['Purple Yam Wet Premix', IngredientUnit::Pack],
            ['Ube Base Premix', IngredientUnit::Pack],
            ['Ube Powder', IngredientUnit::Gram],
            ['Ube Food Color', IngredientUnit::Milliliter],
            ['White Sugar', IngredientUnit::Kilogram],
            ['Confectioner Sugar', IngredientUnit::Kilogram],
            ['All-Purpose Flour', IngredientUnit::Kilogram],
            ['Extra Large Eggs', IngredientUnit::Piece],
            ['Evaporated Filled Milk', IngredientUnit::Can],
            ['Condensed Milk', IngredientUnit::Can],
            ['All-Purpose Cream', IngredientUnit::Pack],
            ['Cream Cheese', IngredientUnit::Kilogram],
            ['Baking Powder', IngredientUnit::Gram],
            ['Cream of Tartar', IngredientUnit::Gram],
            ['Vanilla Extract', IngredientUnit::Milliliter],
            ['Calamansi Syrup', IngredientUnit::Liter],
            ['Cooking Oil', IngredientUnit::Liter],
            ['Vegetable Shortening', IngredientUnit::Kilogram],
        ];

        foreach ($ingredients as [$name, $unit]) {
            Ingredient::create([
                'name' => $name,
                'unit' => $unit,
                'reorder_level' => '0.000',
                'is_active' => true,
            ]);
        }
    }
}
