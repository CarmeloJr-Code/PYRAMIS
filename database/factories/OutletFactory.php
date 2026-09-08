<?php

namespace Database\Factories;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word().' Outlet',
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'is_active' => true,
        ];
    }

    /**
     * The branch that runs production and supplies the others.
     *
     * Not a state on the attributes: the flag is deliberately not
     * mass-assignable, so nothing but a deliberate write can move it.
     */
    public function mainBranch(): static
    {
        return $this->afterCreating(function (Outlet $outlet): void {
            $outlet->is_main_branch = true;
            $outlet->save();
        });
    }

    /**
     * Indicate that the outlet is no longer accepting pickups.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
