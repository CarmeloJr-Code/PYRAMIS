<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->unique()->randomElement([
                'Large (10x14)', 'Medium (7x10)', 'Round (7x3)', 'Heart (6" dia)',
                'Tincan Round', 'Tincan Heart', 'Messy Cup (12 oz)', 'Messy Cup (8 oz)',
                'Slice', 'Layer', 'Tincan/Tub',
            ]),
            'price' => fake()->randomFloat(2, 20, 1500),
            'is_available' => true,
        ];
    }

    /**
     * Indicate that the variant cannot currently be sold.
     */
    public function unavailable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_available' => false,
        ]);
    }
}
