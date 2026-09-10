<?php

namespace Tests\Feature;

use Tests\TestCase;

class SessionCookieTest extends TestCase
{
    public function test_the_session_cookie_is_marked_secure_in_production(): void
    {
        // Render terminates TLS in front of the container, so an unflagged
        // cookie is one the browser will hand back over plain HTTP — and it
        // identifies a signed-in employee's session.
        $this->assertTrue($this->sessionConfigFor('production')['secure']);
    }

    public function test_it_is_not_marked_secure_on_a_local_http_server(): void
    {
        // The flag would stop the cookie being set at all, so nobody could sign
        // in while developing.
        $this->assertFalse($this->sessionConfigFor('local')['secure']);
    }

    public function test_an_explicit_setting_still_wins(): void
    {
        $this->assertTrue($this->sessionConfigFor('local', 'true')['secure']);
    }

    /**
     * The session config as it resolves under a given environment.
     *
     * Read by evaluating the file rather than through config(), which holds
     * whatever was resolved when this test process booted.
     *
     * @return array<string, mixed>
     */
    private function sessionConfigFor(string $environment, ?string $secure = null): array
    {
        $original = [$_SERVER['APP_ENV'] ?? null, $_SERVER['SESSION_SECURE_COOKIE'] ?? null];

        $_SERVER['APP_ENV'] = $environment;

        if ($secure === null) {
            unset($_SERVER['SESSION_SECURE_COOKIE']);
        } else {
            $_SERVER['SESSION_SECURE_COOKIE'] = $secure;
        }

        try {
            return require base_path('config/session.php');
        } finally {
            [$_SERVER['APP_ENV'], $_SERVER['SESSION_SECURE_COOKIE']] = $original;

            if ($original[1] === null) {
                unset($_SERVER['SESSION_SECURE_COOKIE']);
            }
        }
    }
}
