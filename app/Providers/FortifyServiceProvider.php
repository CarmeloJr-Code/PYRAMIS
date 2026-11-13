<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Concerns\PasswordValidationRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    use PasswordValidationRules;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // No password contains whitespace, so any typed or pasted along with
        // one is dropped before it is checked rather than failing the check.
        Fortify::authenticateUsing(function (Request $request): ?Authenticatable {
            $provider = Auth::guard(config('fortify.guard'))->getProvider();
            $password = $this->withoutWhitespace($request->input('password'));

            $user = $provider->retrieveByCredentials([
                Fortify::username() => $request->input(Fortify::username()),
            ]);

            if ($user === null || ! $provider->validateCredentials($user, ['password' => $password])) {
                return null;
            }

            if (config('hashing.rehash_on_login', true)) {
                $provider->rehashPasswordIfRequired($user, ['password' => $password]);
            }

            return $user;
        });

        Fortify::confirmPasswordsUsing(
            fn (Authenticatable $user, ?string $password): bool => Hash::check(
                $this->withoutWhitespace($password),
                $user->getAuthPassword(),
            ),
        );
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $email = Str::transliterate(Str::lower((string) $request->input(Fortify::username())));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                // And per account whatever the address, so guesses spread
                // across many addresses still meet a ceiling. Loose enough
                // that an employee mistyping is never the one it stops.
                Limit::perMinute(10)->by('login-account|'.$email),
            ];
        });
    }
}
