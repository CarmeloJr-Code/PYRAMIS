<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderPayment>
 */
class OrderPaymentFactory extends Factory
{
    /**
     * Define the model's default state: proof sent, not yet checked.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'gcash_reference' => fake()->numerify('#############'),
            'receipt' => null,
            'receipt_mime' => null,
            'submitted_at' => now(),
            'amount' => null,
            'verified_by' => null,
            'verified_at' => null,
        ];
    }

    /**
     * Proof sent with a screenshot of the receipt attached.
     */
    public function withReceipt(): static
    {
        return $this->state(fn (array $attributes) => [
            // A 1×1 transparent PNG.
            'receipt' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'receipt_mime' => 'image/png',
        ]);
    }

    /**
     * Checked by a cashier.
     */
    public function verified(string $amount = '0.00'): static
    {
        return $this->state(fn (array $attributes) => [
            'amount' => $amount,
            'verified_by' => User::factory(),
            'verified_at' => now(),
        ]);
    }
}
