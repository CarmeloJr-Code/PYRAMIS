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
     * Say how much one pack, can or piece of the ingredient holds.
     */
    public function holding(string $amount, IngredientUnit $measure = IngredientUnit::Gram): static
    {
        return $this->state(fn (array $attributes) => [
            'content_per_unit' => $amount,
            'content_measure' => $measure,
        ]);
    }

    /**
     * Say what one level cup of the ingredient weighs, in its grams or milliliters.
     */
    public function cupWeighs(string $weight): static
    {
        return $this->state(fn (array $attributes) => [
            'cup_weight' => $weight,
        ]);
    }

    /**
     * Say what the ingredient arrives in and how many stocked units that is.
     */
    public function boughtIn(string $name, string $size): static
    {
        return $this->state(fn (array $attributes) => [
            'package_name' => $name,
            'package_size' => $size,
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
