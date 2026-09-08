<?php

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'expense_category_id' => ExpenseCategory::factory(),
            'outlet_id' => Outlet::factory(),
            'amount' => fake()->randomFloat(2, 50, 5000),
            'spent_on' => now()->toDateString(),
            'description' => ucfirst(fake()->word().' '.fake()->word()),
            'recorded_by' => User::factory()->cashier(),
        ];
    }

    /**
     * An expense of an exact amount.
     */
    public function amount(string $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
        ]);
    }

    /**
     * An expense filed against a given day.
     */
    public function spentOn(string $date): static
    {
        return $this->state(fn (array $attributes) => [
            'spent_on' => $date,
        ]);
    }
}
