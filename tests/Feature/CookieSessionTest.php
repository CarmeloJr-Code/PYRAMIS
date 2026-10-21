<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Production keeps the session in the encrypted cookie (`render.yaml`), so a
 * page view costs no database round trip to read and rewrite it.
 *
 * Every request here starts from a cold guard and a cold session store, so the
 * only thing that can carry a sign-in from one request to the next is the
 * cookies the previous response set — as it is for a real browser.
 */
class CookieSessionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $jar = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'cookie']);
    }

    public function test_a_sign_in_carries_to_the_next_page_in_the_cookie_alone(): void
    {
        $user = User::factory()->create();

        $this->send('post', route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('employee.dashboard', absolute: false));

        $this->send('get', route('employee.dashboard'))->assertOk();

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('sessions', 0);

        // Browsers drop a cookie past 4 KB, and the sign-in with it.
        foreach ($this->jar as $name => $value) {
            $this->assertLessThan(4096, strlen($name.$value), "The {$name} cookie is too large to be kept.");
        }
    }

    public function test_without_the_cookie_there_is_no_sign_in(): void
    {
        $user = User::factory()->create();

        $this->send('post', route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->jar = [];

        $this->send('get', route('employee.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_session_still_holding_a_valid_cookie_is_closed_once_the_account_is_deactivated(): void
    {
        // The cookie cannot be revoked from the server, so this is what keeps a
        // deactivated employee out: the account is re-read on every request.
        $user = User::factory()->create();

        $this->send('post', route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->send('get', route('employee.dashboard'))->assertOk();

        $user->update(['is_active' => false]);

        $this->send('get', route('employee.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_out_ends_the_session_the_cookie_carried(): void
    {
        $user = User::factory()->create();

        $this->send('post', route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->send('post', route('logout'))->assertRedirect(route('home'));

        $this->send('get', route('employee.dashboard'))->assertRedirect(route('login'));
    }

    /**
     * Send a request carrying only the cookies earlier responses set, then
     * keep whatever this one sets or clears.
     *
     * @param  array<string, mixed>  $data
     */
    private function send(string $method, string $uri, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');

        // The values are already encrypted by the response that set them.
        $this->unencryptedCookies = $this->jar;

        $response = $this->{$method}($uri, $data);

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->jar[$cookie->getName()]);
            } else {
                $this->jar[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}
