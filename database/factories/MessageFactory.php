<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory()->baker(),
            'body' => fake()->sentence(),
        ];
    }

    /**
     * A message saying something in particular.
     */
    public function saying(string $body): static
    {
        return $this->state(fn (array $attributes) => [
            'body' => $body,
        ]);
    }
}
