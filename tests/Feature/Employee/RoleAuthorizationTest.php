<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductionRun;
use App\Models\ProductVariant;
use App\Models\Restock;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every ability guarding the workspace, with the roles the capability
     * matrix in docs/roles-and-permissions.md grants it to, and every screen
     * that ability stands in front of.
     *
     * Grouped by ability rather than listed screen by screen: the matrix is
     * written in capabilities, and a failure that names the capability says
     * which row of it the code disagrees with.
     *
     * @return array<string, array{0: ?string, 1: list<UserRole>, 2: list<string>}>
     */
    public static function capabilityProvider(): array
    {
        return [
            'any signed-in employee' => [null, [UserRole::Administrator, UserRole::Baker, UserRole::Cashier], [
                'employee.dashboard',
                'employee.schedule',
                'employee.reports.index',
                'employee.messages.index',
                'employee.messages.create',
            ]],
            'access-sales' => ['access-sales', [UserRole::Administrator, UserRole::Cashier], [
                'employee.sales.index',
                'employee.sales.create',
                'employee.reports.sales',
            ]],
            'manage-orders' => ['manage-orders', [UserRole::Administrator, UserRole::Cashier], [
                'employee.orders.index',
                'employee.orders.show',
            ]],
            'access-expenses' => ['access-expenses', [UserRole::Administrator, UserRole::Cashier], [
                'employee.expenses.index',
                'employee.expenses.create',
                'employee.reports.expenses',
            ]],
            'manage-expenses' => ['manage-expenses', [UserRole::Administrator], [
                'employee.expenses.categories',
                'employee.expenses.edit',
            ]],
            'access-inventory' => ['access-inventory', [UserRole::Administrator, UserRole::Baker], [
                'employee.inventory.index',
                'employee.inventory.create',
                'employee.inventory.usage',
                'employee.inventory.finished',
                'employee.inventory.show',
                'employee.inventory.edit',
                'employee.reports.inventory',
            ]],
            'access-production' => ['access-production', [UserRole::Administrator, UserRole::Baker], [
                'employee.production',
                'employee.production.runs.index',
                'employee.production.runs.show',
                'employee.production.recipes.index',
                'employee.production.recipes.manage',
                'employee.reports.production',
            ]],
            'access-restocking' => ['access-restocking', [UserRole::Administrator, UserRole::Baker], [
                'employee.restocks.index',
                'employee.restocks.show',
            ]],
            'schedule-restocks' => ['schedule-restocks', [UserRole::Administrator], [
                'employee.restocks.create',
            ]],
            'access-workforce' => ['access-workforce', [UserRole::Administrator], [
                'employee.workforce',
                'employee.workforce.shifts.create',
                'employee.workforce.shifts.show',
                'employee.workforce.shifts.edit',
                'employee.workforce.employees.index',
                'employee.workforce.employees.create',
                'employee.workforce.employees.edit',
                'employee.reports.workforce',
            ]],
            'manage-products' => ['manage-products', [UserRole::Administrator], [
                'employee.products.index',
                'employee.products.create',
                'employee.products.edit',
            ]],
            'manage-outlets' => ['manage-outlets', [UserRole::Administrator], [
                'employee.outlets.index',
                'employee.outlets.create',
                'employee.outlets.edit',
                'employee.reports.outlets',
            ]],
            'access-forecasting' => ['access-forecasting', [UserRole::Administrator], [
                'employee.forecast',
            ]],
        ];
    }

    /**
     * The same list without the screens that authentication alone guards,
     * which have no ability to check.
     *
     * @return array<string, array{0: ?string, 1: list<UserRole>, 2: list<string>}>
     */
    public static function abilityProvider(): array
    {
        return array_filter(
            self::capabilityProvider(),
            fn (array $capability): bool => $capability[0] !== null,
        );
    }

    /**
     * @param  list<UserRole>  $permitted
     * @param  list<string>  $screens  Unused — the provider is shared with the screen tests.
     */
    #[DataProvider('abilityProvider')]
    public function test_each_ability_is_granted_to_exactly_the_roles_the_matrix_names(?string $ability, array $permitted, array $screens): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->make(['role' => $role]);

            $this->assertSame(
                in_array($role, $permitted, strict: true),
                Gate::forUser($user)->allows($ability),
                "[{$ability}] is wrong for the {$role->value}.",
            );
        }
    }

    /**
     * @param  list<UserRole>  $permitted
     * @param  list<string>  $screens
     */
    #[DataProvider('capabilityProvider')]
    public function test_only_permitted_roles_may_open_a_screen(?string $ability, array $permitted, array $screens): void
    {
        foreach ($screens as $screen) {
            $url = $this->urlFor($screen);

            foreach (UserRole::cases() as $role) {
                $user = User::factory()->create(['role' => $role]);

                $response = $this->actingAs($user)->get($url);

                in_array($role, $permitted, strict: true)
                    ? $response->assertOk()
                    : $response->assertForbidden();
            }
        }
    }

    /**
     * @param  list<UserRole>  $permitted  Unused — the provider is shared with the role test.
     * @param  list<string>  $screens
     */
    #[DataProvider('capabilityProvider')]
    public function test_guests_are_redirected_to_the_login_page(?string $ability, array $permitted, array $screens): void
    {
        foreach ($screens as $screen) {
            $this->get($this->urlFor($screen))->assertRedirect(route('login'));
        }
    }

    public function test_a_deactivated_employee_is_turned_away_whatever_their_role(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role, 'is_active' => false]);

            $this->actingAs($user)
                ->get(route('employee.dashboard'))
                ->assertRedirect(route('login'));

            $this->assertGuest();
        }
    }

    public function test_the_sidebar_only_shows_sections_the_role_may_open(): void
    {
        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.dashboard'))
            ->assertSee(route('employee.production'))
            ->assertDontSee(route('employee.sales.index'))
            ->assertDontSee(route('employee.workforce'));
    }

    /**
     * The address of a screen, with a record behind it when the route binds one.
     *
     * The records only have to exist — what a role may see of one is the
     * business of the slice's own test. This asks the narrower question of
     * whether the door opens at all.
     */
    private function urlFor(string $screen): string
    {
        return route($screen, match ($screen) {
            'employee.orders.show' => Order::factory()->create(),
            'employee.outlets.edit' => Outlet::factory()->create(),
            'employee.products.edit' => Product::factory()->create(),
            'employee.expenses.edit' => Expense::factory()->create(),
            'employee.inventory.show', 'employee.inventory.edit' => Ingredient::factory()->create(),
            'employee.restocks.show' => Restock::factory()->create(),
            'employee.production.runs.show' => ProductionRun::factory()->create(),
            'employee.production.recipes.manage' => ProductVariant::factory()->create(),
            'employee.workforce.shifts.show', 'employee.workforce.shifts.edit' => Shift::factory()->create(),
            'employee.workforce.employees.edit' => User::factory()->baker()->create(),
            default => [],
        });
    }
}
