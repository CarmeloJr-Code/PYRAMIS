<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
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
        $this->configureDefaults();
        $this->configureAuthorization();
    }

    /**
     * Define the role-based abilities guarding the employee workspace.
     *
     * Each ability mirrors a row of the capability matrix in
     * docs/roles-and-permissions.md. Abilities are added as later slices add the
     * capabilities they guard — the matrix is not pre-registered here.
     */
    protected function configureAuthorization(): void
    {
        Gate::define('access-sales', fn (User $user): bool => $user->hasRole(
            UserRole::Administrator,
            UserRole::Cashier,
        ));

        Gate::define('access-production', fn (User $user): bool => $user->hasRole(
            UserRole::Administrator,
            UserRole::Baker,
        ));

        Gate::define('access-workforce', fn (User $user): bool => $user->hasRole(
            UserRole::Administrator,
        ));

        // The capability matrix grants Cashier no product rights, and pricing is a
        // management decision under BR-007, so catalogue management is Administrator
        // only — narrower than the Phase 2 spec's "administrator/cashier".
        Gate::define('manage-products', fn (User $user): bool => $user->hasRole(
            UserRole::Administrator,
        ));

        // Outlet records are FR-06 management territory, same reasoning as
        // manage-products: the matrix gives Cashier and Baker no say over them.
        Gate::define('manage-outlets', fn (User $user): bool => $user->hasRole(
            UserRole::Administrator,
        ));

        // "Customer Orders" in the capability matrix — Cashier included, Baker
        // excluded. Handling orders is BR-006 cashier work.
        Gate::define('manage-orders', fn (User $user): bool => $user->hasRole(
            UserRole::Administrator,
            UserRole::Cashier,
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
