<?php

namespace Database\Factories;

use App\Enums\ProductStockMovementType;
use App\Models\Outlet;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductStockMovement>
 */
class ProductStockMovementFactory extends Factory
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
            'outlet_id' => Outlet::factory(),
            'type' => ProductStockMovementType::Produced,
            'quantity' => fake()->numberBetween(1, 40),
            'production_run_id' => null,
            'recorded_by' => User::factory()->baker(),
            'note' => null,
            'occurred_at' => now(),
        ];
    }

    /**
     * A movement of an exact number of units — signed, as it is stored.
     */
    public function quantity(int $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }

    /**
     * A hand-made correction, which always carries its reason.
     */
    public function adjustment(int $quantity, string $note = 'Stock count correction'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductStockMovementType::Adjustment,
            'quantity' => $quantity,
            'note' => $note,
        ]);
    }
}
