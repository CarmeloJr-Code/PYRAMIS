<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a deactivated employee out of the workspace.
 *
 * Guarded at the door rather than inside the login pipeline, because a session
 * can begin in more ways than one — a password, a passkey, a two-factor
 * challenge, or a remembered cookie from before the account was closed. One
 * check on the way in covers all of them, and covers a session that was already
 * open when the account was deactivated.
 */
class EnsureEmployeeIsActive
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => __('This account is no longer active. Speak to your manager.'),
            ]);
        }

        return $next($request);
    }
}
