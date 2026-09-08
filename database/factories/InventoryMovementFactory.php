<?php

namespace Database\Factories;

use App\Enums\InventoryMovementType;
use App\Models\Ingredient;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryMovement>
 */
class InventoryMovementFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'type' => InventoryMovementType::Received,
            'quantity' => fake()->randomFloat(3, 1, 500),
            'recorded_by' => User::factory()->baker(),
            'note' => null,
            'occurred_at' => now(),
        ];
    }

    /**
     * A movement of an exact quantity — signed, as it is stored.
     */
    public function quantity(string $quantity): static
    {
        return $this->state(fn (array $attributes) => [
            'quantity' => $quantity,
        ]);
    }

    /**
     * A hand-made correction, which always carries its reason.
     */
    public function adjustment(string $quantity, string $note = 'Stock count correction'): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InventoryMovementType::Adjustment,
            'quantity' => $quantity,
            'note' => $note,
        ]);
    }
}
