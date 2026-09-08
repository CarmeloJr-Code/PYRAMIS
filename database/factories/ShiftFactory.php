<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
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
            'starts_at' => now()->setTime(9, 0),
            'ends_at' => now()->setTime(17, 0),
            'name' => fake()->randomElement(['Morning bake', 'Counter', 'Afternoon bake', 'Closing']),
            'created_by' => User::factory()->administrator(),
            'notes' => null,
        ];
    }

    /**
     * A shift running between the given times today.
     */
    public function between(string $from, string $to): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => now()->setTimeFromTimeString($from),
            'ends_at' => now()->setTimeFromTimeString($to),
        ]);
    }

    /**
     * A shift on a given day, between the given times.
     */
    public function on(string $date, string $from = '09:00', string $to = '17:00'): static
    {
        return $this->state(fn (array $attributes) => [
            'starts_at' => "{$date} {$from}:00",
            'ends_at' => "{$date} {$to}:00",
        ]);
    }
}
