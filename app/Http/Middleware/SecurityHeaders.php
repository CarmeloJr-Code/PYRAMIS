<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The response headers a browser needs to be told, because it assumes the
 * generous answer otherwise.
 *
 * Three narrow ones, each closing a hole the application cannot close from
 * inside its own markup. Deliberately not a Content-Security-Policy: Livewire
 * and Alpine both work through inline handlers, so a policy strict enough to be
 * worth having would need a nonce threaded through every response, and a policy
 * loose enough to avoid that only looks like protection.
 */
class SecurityHeaders
{
    /**
     * The headers added to every response that carries one.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            // Customer notes, chat messages and product names are all rendered
            // from what someone typed. Blade escapes them, but a browser that
            // sniffs a response as something other than what it was labelled
            // can undo that on its own.
            'X-Content-Type-Options' => 'nosniff',

            // Nothing here is meant to be embedded, and an employee workspace
            // inside somebody else's frame is a clickjacking target with a
            // "Mark as Completed" button in it.
            'X-Frame-Options' => 'DENY',

            // Order references sit in the path of the tracking page. Without
            // this a customer following a link out of that page would hand the
            // reference to whatever they clicked.
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headers() as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }
}
