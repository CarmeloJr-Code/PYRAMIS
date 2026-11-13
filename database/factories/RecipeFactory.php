<?php

namespace Database\Factories;

use App\Enums\RecipeStatus;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\User;
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
            'status' => RecipeStatus::Approved,
        ];
    }

    /**
     * A recipe a Baker has saved and no Administrator has looked at yet.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RecipeStatus::Pending,
            'submitted_by' => User::factory()->baker(),
            'submitted_at' => now(),
        ]);
    }

    /**
     * A recipe an Administrator sent back to be corrected.
     */
    public function rejected(string $note = 'Check the quantities.'): static
    {
        return $this->pending()->state(fn (array $attributes) => [
            'status' => RecipeStatus::Rejected,
            'reviewed_by' => User::factory()->administrator(),
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);
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
