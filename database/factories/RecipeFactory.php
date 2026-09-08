<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Recipe>
 */
class RecipeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_variant_id' => ProductVariant::factory(),
            'yield_quantity' => 1,
            'notes' => null,
        ];
    }

    /**
     * A recipe whose batch makes several units at once.
     */
    public function yielding(int $units): static
    {
        return $this->state(fn (array $attributes) => [
            'yield_quantity' => $units,
        ]);
    }
}
