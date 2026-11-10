<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Feature\EmailAddressTest;
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
            ->set('email', 'rosa@purpleyam.store')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasNoErrors();

        $employee = User::firstWhere('email', 'rosa@purpleyam.store');

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
        User::factory()->create(['email' => 'taken@purpleyam.store']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Someone')
            ->set('email', 'taken@purpleyam.store')
            ->set('role', '')
            ->call('save')
            ->assertHasErrors(['email', 'role']);

        $this->assertSame(1, User::where('email', 'taken@purpleyam.store')->count());
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

    #[TestWith(['R2D2 Reyes', 'regex'])]
    #[TestWith(['Rosa!', 'regex'])]
    #[TestWith(['R', 'min'])]
    public function test_a_name_with_anything_but_letters_is_refused(string $name, string $rule): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', $name)
            ->set('email', 'rosa@purpleyam.store')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasErrors(['name' => $rule]);

        $this->assertDatabaseMissing('users', ['email' => 'rosa@purpleyam.store']);
    }

    public function test_a_digit_in_the_name_says_what_it_wants(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'R2D2 Reyes')
            ->set('email', 'rosa@purpleyam.store')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertSee('Please use letters only in the name.');
    }

    public function test_periods_apostrophes_hyphens_and_accents_are_part_of_a_name(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', "Ma. Rosa O'Brien-Peñaflor")
            ->set('email', 'rosa@purpleyam.store')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['name' => "Ma. Rosa O'Brien-Peñaflor"]);
    }

    public function test_the_name_and_email_are_stored_without_surrounding_spaces(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', '  Rosa Baker ')
            ->set('email', ' rosa@purpleyam.store ')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['name' => 'Rosa Baker', 'email' => 'rosa@purpleyam.store']);
    }

    public function test_an_email_padded_with_spaces_is_still_a_duplicate(): void
    {
        User::factory()->create(['email' => 'taken@purpleyam.store']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Someone Else')
            ->set('email', 'taken@purpleyam.store ')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);

        $this->assertSame(1, User::where('email', 'taken@purpleyam.store')->count());
    }

    public function test_an_email_differing_only_in_case_is_still_a_duplicate(): void
    {
        User::factory()->create(['email' => 'taken@purpleyam.store']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Someone Else')
            ->set('email', 'Taken@PurpleYam.Store')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasErrors(['email' => 'unique']);

        $this->assertDatabaseMissing('users', ['name' => 'Someone Else']);
    }

    public function test_an_employee_saved_with_capitals_signs_in_however_they_type_it(): void
    {
        $component = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Rosa Baker')
            ->set('email', 'Rosa@PurpleYam.Store')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'rosa@purpleyam.store']);

        auth()->logout();

        $this->post(route('login.store'), [
            'email' => 'ROSA@purpleyam.store',
            'password' => $component->get('generatedPassword'),
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs(User::firstWhere('email', 'rosa@purpleyam.store'));
    }

    #[DataProviderExternal(EmailAddressTest::class, 'notAnEmailProvider')]
    public function test_an_email_that_is_not_one_is_refused(string $email): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Rosa Baker')
            ->set('email', $email)
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasErrors('email')
            ->assertSee('Please enter a working email address, like ana@gmail.com.');

        $this->assertDatabaseMissing('users', ['name' => 'Rosa Baker']);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_an_email_is_required(string $email): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Rosa Baker')
            ->set('email', $email)
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasErrors(['email' => 'required']);

        $this->assertDatabaseMissing('users', ['name' => 'Rosa Baker']);
    }

    public function test_an_email_over_the_limit_is_refused(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Rosa Baker')
            ->set('email', str_repeat('a', 250).'@purpleyam.store')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasErrors(['email' => 'max']);

        $this->assertDatabaseMissing('users', ['name' => 'Rosa Baker']);
    }

    public function test_editing_an_employee_keeps_their_own_email_and_lowercases_a_new_one(): void
    {
        $employee = User::factory()->baker()->create(['email' => 'rosa@purpleyam.store']);
        $admin = User::factory()->administrator()->create();

        Livewire::actingAs($admin)
            ->test('pages::employee.workforce.employees.manage', ['user' => $employee])
            ->call('save')
            ->assertHasNoErrors();

        Livewire::actingAs($admin)
            ->test('pages::employee.workforce.employees.manage', ['user' => $employee])
            ->set('email', 'Rosa.Baker@PurpleYam.Store')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('rosa.baker@purpleyam.store', $employee->fresh()->email);
    }

    public function test_a_new_employee_can_be_given_a_mobile_number_for_shift_texts(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Rosa Baker')
            ->set('email', 'rosa@purpleyam.store')
            ->set('phone', ' 09181112222 ')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('09181112222', User::firstWhere('email', 'rosa@purpleyam.store')->phone);
    }

    public function test_an_employee_needs_no_mobile_number(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->set('name', 'Rosa Baker')
            ->set('email', 'rosa@purpleyam.store')
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(User::firstWhere('email', 'rosa@purpleyam.store')->phone);
    }

    public function test_an_administrator_changes_or_clears_an_employees_number(): void
    {
        $employee = User::factory()->baker()->create(['phone' => '09181112222']);
        $admin = User::factory()->administrator()->create();

        Livewire::actingAs($admin)
            ->test('pages::employee.workforce.employees.manage', ['user' => $employee])
            ->assertSet('phone', '09181112222')
            ->set('phone', '09183334444')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('09183334444', $employee->fresh()->phone);

        Livewire::actingAs($admin)
            ->test('pages::employee.workforce.employees.manage', ['user' => $employee])
            ->set('phone', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull($employee->fresh()->phone);
    }

    #[TestWith(['9181112222'])]
    #[TestWith(['091811122223'])]
    #[TestWith(['+639181112222'])]
    #[TestWith(['0918-111-2222'])]
    public function test_a_number_that_is_not_an_11_digit_mobile_is_refused(string $phone): void
    {
        $employee = User::factory()->baker()->create(['phone' => null]);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage', ['user' => $employee])
            ->set('phone', $phone)
            ->call('save')
            ->assertHasErrors(['phone']);

        $this->assertNull($employee->fresh()->phone);
    }

    public function test_the_fields_restrict_what_can_be_typed_into_them(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.employees.manage')
            ->assertSeeHtml("keep(\$el, /[^\\p{L}\\s.'-]/gu)")
            ->assertSeeHtml('keep($el, /\s/g)')
            ->assertSeeHtml('maxlength="255"');
    }

    public function test_the_edit_form_cannot_close_the_account_using_it(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.employees.manage', ['user' => $administrator])
            ->set('is_active', false)
            ->call('save')
            ->assertHasErrors('is_active');

        $this->assertTrue($administrator->fresh()->is_active);
    }

    public function test_the_edit_form_cannot_make_the_last_administrator_something_else(): void
    {
        $administrator = User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.employees.manage', ['user' => $administrator])
            ->set('role', UserRole::Cashier->value)
            ->call('save')
            ->assertHasErrors('role');

        $this->assertSame(UserRole::Administrator, $administrator->fresh()->role);
    }

    public function test_the_edit_form_cannot_close_the_last_administrator(): void
    {
        $administrator = User::factory()->administrator()->create();
        // Closed already, so the one being edited is the last one standing.
        $other = User::factory()->administrator()->create(['is_active' => false]);

        Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.employees.manage', ['user' => $administrator])
            ->set('role', UserRole::Baker->value)
            ->call('save')
            ->assertHasErrors('role');

        $this->assertSame(UserRole::Administrator, $administrator->fresh()->role);
        $this->assertFalse($other->fresh()->is_active);
    }

    public function test_an_administrator_may_step_down_while_another_remains(): void
    {
        $administrator = User::factory()->administrator()->create();
        User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.employees.manage', ['user' => $administrator])
            ->set('role', UserRole::Cashier->value)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(UserRole::Cashier, $administrator->fresh()->role);
    }

    public function test_a_page_left_open_stops_working_once_its_account_is_closed(): void
    {
        $administrator = User::factory()->administrator()->create();
        $employee = User::factory()->baker()->create();

        $page = Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.employees.index');

        // Closed by someone else while the page sits open.
        $administrator->update(['is_active' => false]);

        $page->call('toggleActive', $employee->id)->assertForbidden();

        $this->assertTrue($employee->fresh()->is_active);
    }
}
