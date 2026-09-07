<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OutletManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The outlet routes, which are Administrator-only.
     *
     * @return array<string, array{0: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'index' => ['employee.outlets.index'],
            'create' => ['employee.outlets.create'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function test_only_administrators_may_manage_outlets(string $route): void
    {
        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route($route));

            $role === UserRole::Administrator
                ? $response->assertOk()
                : $response->assertForbidden();
        }
    }

    #[DataProvider('routeProvider')]
    public function test_guests_are_redirected_to_the_login_page(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_the_component_itself_refuses_non_administrators(): void
    {
        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.outlets.index')
            ->assertForbidden();
    }

    public function test_an_administrator_creates_an_outlet(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.outlets.manage')
            ->set('name', 'Valencia Outlet')
            ->set('address', 'Valencia City, Bukidnon')
            ->set('phone', '09171234567')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('outlets', [
            'name' => 'Valencia Outlet',
            'address' => 'Valencia City, Bukidnon',
            'is_active' => true,
        ]);
    }

    public function test_it_rejects_a_blank_name_or_address(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.outlets.manage')
            ->set('name', '')
            ->set('address', '')
            ->call('save')
            ->assertHasErrors(['name', 'address']);

        $this->assertDatabaseEmpty('outlets');
    }

    public function test_it_rejects_a_duplicate_outlet_name(): void
    {
        Outlet::factory()->create(['name' => 'Main Branch']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.outlets.manage')
            ->set('name', 'Main Branch')
            ->set('address', 'Somewhere else')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, Outlet::where('name', 'Main Branch')->count());
    }

    public function test_an_administrator_can_close_and_reopen_an_outlet(): void
    {
        $outlet = Outlet::factory()->create(['is_active' => true]);

        $component = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.outlets.index')
            ->call('toggleActive', $outlet->id);

        $this->assertFalse($outlet->fresh()->is_active);

        $component->call('toggleActive', $outlet->id);

        $this->assertTrue($outlet->fresh()->is_active);
    }
}
