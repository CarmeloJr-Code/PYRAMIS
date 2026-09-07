<?php

namespace Database\Factories;

use App\Enums\SaleStatus;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'order_id' => null,
            'recorded_by' => User::factory()->cashier(),
            'status' => SaleStatus::Completed,
            'sold_at' => now(),
            'voided_at' => null,
        ];
    }

    /**
     * Indicate that the sale was voided.
     */
    public function voided(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SaleStatus::Voided,
            'voided_at' => now(),
        ]);
    }

    /**
     * Record the sale on a particular day.
     */
    public function soldAt(\DateTimeInterface $soldAt): static
    {
        return $this->state(fn (array $attributes) => [
            'sold_at' => $soldAt,
        ]);
    }
}
