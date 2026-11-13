<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::resetPasswords());
    }

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get(route('password.request'));

        $response->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_the_link_is_sent_whatever_the_case_of_the_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'ana@purpleyam.store']);

        $this->post(route('password.request'), ['email' => ' Ana@PurpleYam.Store '])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_a_link_request_without_an_email_address_is_refused(): void
    {
        Notification::fake();

        $this->post(route('password.request'), ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_the_email_fields_are_capped_and_autofilled(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('maxlength="255"', false)
            ->assertSee('autocomplete="email"', false);

        $this->get(route('password.reset', 'a-token'))
            ->assertOk()
            ->assertSee('maxlength="255"', false)
            ->assertSee('autocomplete="email"', false);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get(route('password.reset', $notification->token));

            $response->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login', absolute: false));

            return true;
        });
    }

    public function test_a_new_password_containing_a_space_is_refused(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.request'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post(route('password.update'), [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'new password',
                'password_confirmation' => 'new password',
            ])->assertSessionHasErrors('password');

            return true;
        });

        $this->assertTrue(Hash::check('password', $user->refresh()->password));
    }

    public function test_the_password_fields_refuse_typed_spaces(): void
    {
        $this->get(route('password.reset', 'a-token'))
            ->assertOk()
            ->assertSee('data-no-spaces', false);
    }
}
