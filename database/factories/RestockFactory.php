<?php

namespace Database\Factories;

use App\Enums\RestockStatus;
use App\Models\Outlet;
use App\Models\Restock;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Restock>
 */
class RestockFactory extends Factory
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
            'status' => RestockStatus::Requested,
            'scheduled_for' => now()->toDateString(),
            'requested_by' => User::factory()->administrator(),
            'notes' => null,
        ];
    }

    /**
     * A restock the Baker has started setting aside.
     */
    public function preparing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RestockStatus::Preparing,
            'prepared_at' => now(),
            'prepared_by' => User::factory()->baker(),
        ]);
    }

    /**
     * A restock whose goods reached the outlet, optionally on a given day.
     */
    public function delivered(?string $on = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RestockStatus::Delivered,
            'prepared_at' => $on ?? now(),
            'prepared_by' => User::factory()->baker(),
            'delivered_at' => $on ?? now(),
            'delivered_by' => User::factory()->baker(),
        ]);
    }

    /**
     * A restock called off before it travelled.
     */
    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RestockStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * A restock scheduled for a given day.
     */
    public function scheduledFor(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'scheduled_for' => $date,
        ]);
    }
}
