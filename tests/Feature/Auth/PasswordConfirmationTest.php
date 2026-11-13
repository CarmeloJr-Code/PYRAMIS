<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshDatabase;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('password.confirm'));

        $response->assertOk();
        $response->assertDontSee('Confirm with passkey');
        $response->assertSee('data-no-spaces', false);
    }

    public function test_spaces_entered_with_the_password_are_ignored(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => ' pass word '])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('auth.password_confirmed_at');
    }

    public function test_a_wrong_password_is_not_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password')
            ->assertSessionMissing('auth.password_confirmed_at');
    }
}
