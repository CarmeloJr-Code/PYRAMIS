<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmployeeRecordsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The employee-record routes, which are Administrator-only.
     *
     * @return array<string, array{0: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'index' => ['employee.workforce.employees.index'],
            'create' => ['employee.workforce.employees.create'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function test_only_administrators_may_manage_employee_records(string $route): void
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

    public function test_an_administrator_creates_an_employee_and_sees_the_password_once(): void
    {
        $component = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Rosa Baker')
            ->set('email', 'rosa@purpleyam.test')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasNoErrors();

        $employee = User::firstWhere('email', 'rosa@purpleyam.test');

        $this->assertNotNull($employee);
        $this->assertSame(UserRole::Baker, $employee->role);
        $this->assertTrue($employee->is_active);

        // Provisioned accounts are verified by the Administrator creating them,
        // since no mailer is configured to send a link.
        $this->assertNotNull($employee->email_verified_at);

        $password = $component->get('generatedPassword');

        $this->assertNotEmpty($password);
        $this->assertTrue(auth()->validate(['email' => $employee->email, 'password' => $password]));

        // Shown on the screen, and nowhere in the row.
        $component->assertSee($password);
        $this->assertNotSame($password, $employee->password);
    }

    public function test_it_refuses_a_duplicate_email_or_a_missing_role(): void
    {
        User::factory()->create(['email' => 'taken@purpleyam.test']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Someone')
            ->set('email', 'taken@purpleyam.test')
            ->set('role', '')
            ->call('save')
            ->assertHasErrors(['email', 'role']);

        $this->assertSame(1, User::where('email', 'taken@purpleyam.test')->count());
    }

    public function test_an_administrator_edits_an_employee(): void
    {
        $employee = User::factory()->cashier()->create(['name' => 'Old Name']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage', ['user' => $employee])
            ->set('name', 'New Name')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasNoErrors();

        $employee->refresh();

        $this->assertSame('New Name', $employee->name);
        $this->assertSame(UserRole::Baker, $employee->role);
    }

    public function test_closing_an_account_keeps_the_employee_and_their_history(): void
    {
        $employee = User::factory()->baker()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.index')
            ->call('toggleActive', $employee->id);

        $this->assertFalse($employee->fresh()->is_active);
        $this->assertDatabaseHas('users', ['id' => $employee->id]);
    }

    public function test_an_administrator_cannot_close_their_own_account(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.employees.index')
            ->call('toggleActive', $administrator->id);

        $this->assertTrue($administrator->fresh()->is_active);
    }

    public function test_the_last_administrator_cannot_be_closed(): void
    {
        $administrator = User::factory()->administrator()->create();
        $other = User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.employees.index')
            // The only other administrator goes first, leaving one standing.
            ->call('toggleActive', $other->id)
            ->call('toggleActive', $administrator->id);

        $this->assertFalse($other->fresh()->is_active);
        $this->assertTrue($administrator->fresh()->is_active);
    }

    public function test_closed_accounts_are_hidden_unless_asked_for(): void
    {
        User::factory()->baker()->create(['name' => 'Still Here']);
        User::factory()->cashier()->create(['name' => 'Long Gone', 'is_active' => false]);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.index')
            ->assertSee('Still Here')
            ->assertDontSee('Long Gone')
            ->set('includeFormer', true)
            ->assertSee('Long Gone');
    }

    public function test_a_closed_account_is_not_offered_for_a_shift(): void
    {
        $shift = Shift::factory()->for(Outlet::factory()->create())->create();

        User::factory()->baker()->create(['name' => 'Long Gone', 'is_active' => false]);
        User::factory()->baker()->create(['name' => 'Still Here']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.show', ['shift' => $shift])
            ->assertSee('Still Here')
            ->assertDontSee('Long Gone');

        $this->assertSame(0, $shift->assignments()->count());
    }

    public function test_a_closed_account_is_turned_away_from_the_workspace(): void
    {
        $employee = User::factory()->baker()->create(['is_active' => false]);

        $this->actingAs($employee)
            ->get(route('employee.dashboard'))
            ->assertRedirect(route('login'));

        // And the session that was open is gone, not merely bounced.
        $this->assertGuest();
    }

    public function test_a_closed_account_is_turned_away_from_settings_too(): void
    {
        $employee = User::factory()->cashier()->create(['is_active' => false]);

        $this->actingAs($employee)
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_an_active_employee_is_let_through(): void
    {
        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.dashboard'))
            ->assertOk();
    }
}
