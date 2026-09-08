<?php

namespace Database\Factories;

use App\Models\ProductionRun;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionRun>
 */
class ProductionRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $recipe = Recipe::factory();

        return [
            'recipe_id' => $recipe,
            // The run has to make the size its recipe describes, so the variant
            // is taken from the recipe rather than made up separately.
            'product_variant_id' => fn (array $attributes): int => Recipe::findOrFail($attributes['recipe_id'])->product_variant_id,
            'quantity' => fake()->numberBetween(1, 20),
            'recorded_by' => User::factory()->baker(),
            'notes' => null,
            'produced_at' => now(),
        ];
    }

    /**
     * A run of an exact number of units.
     */
    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }
}
