<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject' => ucfirst(fake()->word().' '.fake()->word()),
            'started_by' => User::factory()->administrator(),
        ];
    }

    /**
     * A conversation with the given employees in it.
     *
     * @param  array<int, User>  $members
     */
    public function withMembers(array $members): static
    {
        return $this->afterCreating(function (Conversation $conversation) use ($members): void {
            foreach ($members as $member) {
                $conversation->participants()->firstOrCreate(['user_id' => $member->id]);
            }
        });
    }
}
