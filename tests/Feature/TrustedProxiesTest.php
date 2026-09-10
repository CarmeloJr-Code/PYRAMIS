<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The deployed app sits behind Cloudflare and Render's load balancer, so what
 * it believes about the caller comes from forwarded headers rather than from
 * the socket. These tests pin the three decisions in that configuration:
 * the address is taken from the chain, the scheme is taken from the chain, and
 * the host deliberately is not.
 */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Run a request through the trusted-proxy middleware as the app configures
     * it, and hand back the request as the rest of the app would see it.
     */
    private function forwarded(array $headers): Request
    {
        // Over plain HTTP, which is how the terminating proxy reaches the
        // container: anything the app concludes about TLS has to come from the
        // forwarded headers.
        $request = Request::create('http://pyramis.test/', server: ['REMOTE_ADDR' => '10.0.0.1']);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $seen = null;

        (new TrustProxies)->handle($request, function (Request $request) use (&$seen): void {
            $seen = $request;
        });

        return $seen;
    }

    public function test_the_caller_is_the_first_address_in_the_forwarded_chain(): void
    {
        // Render sets the first entry itself, so this is the customer and the
        // one after it is the hop that carried them.
        $request = $this->forwarded(['X-Forwarded-For' => '203.0.113.9, 172.16.0.4']);

        $this->assertSame('203.0.113.9', $request->ip());
    }

    public function test_without_a_forwarded_chain_the_caller_is_the_socket(): void
    {
        $request = $this->forwarded([]);

        $this->assertSame('10.0.0.1', $request->ip());
    }

    public function test_the_scheme_is_taken_from_the_chain(): void
    {
        // The proxy terminates TLS and reaches the container over plain HTTP.
        // Untrusted, every generated URL would come out http://.
        $this->assertTrue($this->forwarded(['X-Forwarded-Proto' => 'https'])->isSecure());

        $this->assertFalse($this->forwarded([])->isSecure());
    }

    public function test_the_forwarded_address_survives_a_real_request(): void
    {
        // The three tests above drive the middleware directly, which proves the
        // configuration but not that anything runs it. This goes through the
        // storefront and the whole global stack with it.
        $this->get('/', ['X-Forwarded-For' => '203.0.113.9'])->assertOk();

        $this->assertSame('203.0.113.9', request()->ip());
    }

    public function test_a_forwarded_host_is_not_trusted(): void
    {
        // Left out of the trusted set on purpose: a host the caller chooses
        // rewrites every link the app generates, password resets included.
        $request = $this->forwarded([
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Host' => 'attacker.example',
        ]);

        $this->assertSame('pyramis.test', $request->getHost());
    }
}
