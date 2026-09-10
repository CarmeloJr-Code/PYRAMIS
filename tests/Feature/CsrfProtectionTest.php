<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The forged-post check, actually exercised.
 *
 * Laravel waves the check through while the suite is running — the middleware
 * asks whether it is in a test before it asks whether the token is right — so a
 * post without a token passes in an ordinary test whether the protection is
 * wired up or not. These tests take the bypass away first, which is the only
 * way the assertion means anything.
 */
class CsrfProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stop the middleware recognising the suite, so it does its actual job.
     */
    private function enforceCsrf(): void
    {
        $this->app['env'] = 'local';
    }

    public function test_a_sign_in_without_a_token_is_refused(): void
    {
        $user = User::factory()->cashier()->create();

        $this->enforceCsrf();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(419);

        // The credentials were right. Only the token was missing, and that was
        // enough — which is the whole point.
        $this->assertGuest();
    }

    public function test_a_sign_in_with_its_token_goes_through(): void
    {
        $user = User::factory()->cashier()->create();

        $this->enforceCsrf();

        $this->withSession(['_token' => 'a-known-token'])
            ->post(route('login.store'), [
                '_token' => 'a-known-token',
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('employee.dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_token_is_no_better_than_none(): void
    {
        $user = User::factory()->cashier()->create();

        $this->enforceCsrf();

        $this->withSession(['_token' => 'a-known-token'])
            ->post(route('login.store'), [
                '_token' => 'somebody-elses-token',
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertStatus(419);

        $this->assertGuest();
    }

    public function test_signing_out_needs_a_token_too(): void
    {
        // Otherwise any page on the internet can end an employee's session for
        // them, which is a nuisance rather than a breach, but a real one.
        $user = User::factory()->cashier()->create();

        $this->actingAs($user);
        $this->enforceCsrf();

        $this->post(route('logout'))->assertStatus(419);

        $this->assertAuthenticatedAs($user);
    }
}
