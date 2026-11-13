<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
    }

    public function test_the_workspace_carries_the_purple_yam_mark(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('purple-yam-logo', false)
            ->assertSee('/favicon.svg?v=2', false);

        $this->actingAs(User::factory()->create())
            ->get(route('employee.dashboard'))
            ->assertOk()
            ->assertSee('purple-yam-logo', false);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('employee.dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_the_email_is_matched_whatever_its_case_or_surrounding_spaces(): void
    {
        $user = User::factory()->create(['email' => 'ana@purpleyam.store']);

        $this->post(route('login.store'), [
            'email' => '  Ana@PurpleYam.Store ',
            'password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_the_email_field_is_capped_and_autofilled(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('type="email"', false)
            ->assertSee('maxlength="255"', false)
            ->assertSee('autocomplete="email"', false);
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertSessionHasErrorsIn('email');

        $this->assertGuest();
    }

    public function test_spaces_typed_or_pasted_with_the_password_are_ignored(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => " pass\tword ",
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_password_with_spaces_is_still_refused(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'pass word wrong',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_email_is_refused(): void
    {
        $this->post(route('login.store'), [
            'email' => 'nobody@purpleyam.store',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_password_field_refuses_typed_spaces(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('data-no-spaces', false);
    }

    public function test_a_spaced_password_still_reaches_the_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        $user = User::factory()->withTwoFactor()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password ',
        ])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    }

    public function test_users_with_two_factor_enabled_are_redirected_to_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]);

        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_the_workspace_is_served_under_the_employee_prefix(): void
    {
        $this->assertSame('/employee/login', route('login', absolute: false));
        $this->assertSame('/employee/dashboard', route('employee.dashboard', absolute: false));
    }

    public function test_public_registration_is_not_available(): void
    {
        $this->assertFalse(Route::has('register'));

        $this->get('/register')->assertNotFound();
        $this->get('/employee/register')->assertNotFound();
    }

    public function test_passkey_sign_in_is_not_available(): void
    {
        $this->assertFalse(Route::has('passkey.login'));
        $this->assertFalse(Route::has('passkey.login-options'));

        $this->get(route('login'))->assertDontSee('Sign in with a passkey');
        $this->get('/employee/passkeys/login/options')->assertNotFound();
        $this->post('/employee/passkeys/login')->assertNotFound();
    }

    public function test_the_public_landing_page_offers_neither_sign_in_nor_sign_up(): void
    {
        // The workspace is reached only by its own address.
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee(route('login'))
            ->assertDontSee('Employee sign in')
            ->assertDontSee('Register');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('home'));

        $this->assertGuest();
    }

    public function test_sign_in_is_refused_after_five_wrong_passwords_from_one_address(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_sign_in_is_refused_after_ten_wrong_passwords_however_many_addresses_they_came_from(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 10) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => "203.0.113.{$attempt}"])
                ->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.200'])
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_a_password_reset_is_offered_only_where_its_email_would_arrive(): void
    {
        config(['mail.default' => 'log']);

        $this->get(route('login'))->assertOk()->assertDontSeeText('Forgot your password?');

        config(['mail.default' => 'smtp']);

        $this->get(route('login'))->assertOk()->assertSeeText('Forgot your password?');
    }
}
