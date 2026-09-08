<?php

namespace Database\Factories;

use App\Models\ProductVariant;
use App\Models\Restock;
use App\Models\RestockItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RestockItem>
 */
class RestockItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restock_id' => Restock::factory(),
            'product_variant_id' => ProductVariant::factory(),
            'quantity_requested' => fake()->numberBetween(1, 20),
            'quantity_prepared' => null,
        ];
    }

    /**
     * A line asking for an exact number of units.
     */
    public function requesting(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_requested' => $quantity,
        ]);
    }

    /**
     * A line the Baker has already set aside.
     */
    public function prepared(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity_prepared' => $quantity,
        ]);
    }
}
