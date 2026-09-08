<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The roles permitted to open each workspace section, from the capability
     * matrix in docs/roles-and-permissions.md.
     *
     * @return array<string, array{0: string, 1: list<UserRole>}>
     */
    public static function sectionProvider(): array
    {
        return [
            'sales' => ['employee.sales.index', [UserRole::Administrator, UserRole::Cashier]],
            'inventory' => ['employee.inventory.index', [UserRole::Administrator, UserRole::Baker]],
            'production' => ['employee.production', [UserRole::Administrator, UserRole::Baker]],
            'workforce' => ['employee.workforce', [UserRole::Administrator]],
        ];
    }

    /**
     * @param  list<UserRole>  $permitted
     */
    #[DataProvider('sectionProvider')]
    public function test_only_permitted_roles_may_open_a_section(string $route, array $permitted): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);

            $response = $this->actingAs($user)->get(route($route));

            in_array($role, $permitted, strict: true)
                ? $response->assertOk()
                : $response->assertForbidden();
        }
    }

    /**
     * @param  list<UserRole>  $permitted  Unused — the provider is shared with the role test.
     */
    #[DataProvider('sectionProvider')]
    public function test_guests_are_redirected_to_the_login_page(string $route, array $permitted): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_the_sidebar_only_shows_sections_the_role_may_open(): void
    {
        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.dashboard'))
            ->assertSee(route('employee.production'))
            ->assertDontSee(route('employee.sales.index'))
            ->assertDontSee(route('employee.workforce'));
    }
}
