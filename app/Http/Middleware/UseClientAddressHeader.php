<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the caller's address from the one header the caller cannot write.
 *
 * X-Forwarded-For is a chain every hop appends to, and neither Cloudflare nor
 * Render clears what arrived: a caller who sends their own gets it carried
 * through as the first entry, which is the entry read when the whole chain is
 * trusted. Every limiter keyed by address then counts whatever the caller
 * typed.
 *
 * Cloudflare sits in front of every Render service and writes the address it
 * accepted the connection from into a header of its own, replacing anything
 * sent under that name. Where that header is configured and present, it is the
 * address — and the forwarded chain is dropped, so nothing downstream can
 * prefer it. Unconfigured, as locally and under test, or absent, nothing
 * changes.
 */
class UseClientAddressHeader
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = config('app.client_address_header');

        if (is_string($header) && $header !== '') {
            $address = trim((string) $request->headers->get($header));

            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
                $request->server->set('REMOTE_ADDR', $address);
                $request->headers->remove('X-Forwarded-For');
            }
        }

        return $next($request);
    }
}
