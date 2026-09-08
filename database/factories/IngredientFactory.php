<?php

namespace Database\Factories;

use App\Enums\IngredientUnit;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'unit' => fake()->randomElement(IngredientUnit::cases()),
            // No reorder level by default, so nothing reads as low until a test
            // says it should.
            'reorder_level' => '0.000',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the ingredient is no longer being stocked.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Give the ingredient a level below which it counts as low.
     */
    public function reorderAt(string $level): static
    {
        return $this->state(fn (array $attributes) => [
            'reorder_level' => $level,
        ]);
    }
}
