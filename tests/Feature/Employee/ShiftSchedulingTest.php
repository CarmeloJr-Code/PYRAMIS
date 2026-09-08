<?php

namespace Tests\Feature\Employee;

use App\Actions\AssignShift;
use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ShiftSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
    }

    /**
     * The workforce routes, which are Administrator-only.
     *
     * @return array<string, array{0: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'index' => ['employee.workforce'],
            'create' => ['employee.workforce.shifts.create'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function test_only_administrators_may_manage_the_workforce(string $route): void
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

    public function test_a_baker_cannot_open_a_shift_or_its_form(): void
    {
        $shift = Shift::factory()->for($this->outlet)->create();
        $baker = User::factory()->baker()->create();

        $this->actingAs($baker)->get(route('employee.workforce.shifts.show', $shift))->assertForbidden();
        $this->actingAs($baker)->get(route('employee.workforce.shifts.edit', $shift))->assertForbidden();
    }

    public function test_an_administrator_creates_a_shift(): void
    {
        $administrator = User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.manage')
            ->set('name', 'Morning bake')
            ->set('outlet_id', $this->outlet->id)
            ->set('date', '2026-09-10')
            ->set('starts_at', '05:00')
            ->set('ends_at', '13:00')
            ->set('notes', 'Ube cakes for the weekend')
            ->call('save')
            ->assertHasNoErrors();

        $shift = Shift::firstWhere('name', 'Morning bake');

        $this->assertNotNull($shift);
        $this->assertSame($this->outlet->id, $shift->outlet_id);
        $this->assertSame($administrator->id, $shift->created_by);
        $this->assertSame('2026-09-10 05:00:00', $shift->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 13:00:00', $shift->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame('8h', $shift->duration());
    }

    public function test_a_shift_cannot_end_before_it_starts(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.manage')
            ->set('name', 'Impossible')
            ->set('outlet_id', $this->outlet->id)
            ->set('date', '2026-09-10')
            ->set('starts_at', '13:00')
            ->set('ends_at', '05:00')
            ->call('save')
            ->assertHasErrors('ends_at');

        $this->assertSame(0, Shift::count());
    }

    public function test_it_refuses_a_shift_with_no_name_or_a_closed_location(): void
    {
        $closed = Outlet::factory()->inactive()->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.manage')
            ->set('name', '')
            ->set('outlet_id', $closed->id)
            ->set('date', '2026-09-10')
            ->call('save')
            ->assertHasErrors(['name', 'outlet_id']);

        $this->assertSame(0, Shift::count());
    }

    public function test_an_administrator_assigns_an_employee_to_a_shift(): void
    {
        $shift = Shift::factory()->for($this->outlet)->between('09:00', '17:00')->create();
        $baker = User::factory()->baker()->create(['name' => 'Rosa Baker']);
        $administrator = User::factory()->administrator()->create();

        Livewire::actingAs($administrator)
            ->test('pages::employee.workforce.show', ['shift' => $shift])
            ->set('employeeId', $baker->id)
            ->call('assign')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shift_assignments', [
            'shift_id' => $shift->id,
            'user_id' => $baker->id,
            'assigned_by' => $administrator->id,
        ]);
    }

    public function test_an_overlapping_assignment_is_refused(): void
    {
        $baker = User::factory()->baker()->create(['name' => 'Rosa Baker']);

        $morning = Shift::factory()->for($this->outlet)->between('09:00', '17:00')->create(['name' => 'Morning bake']);
        ShiftAssignment::factory()->for($morning)->for($baker)->create();

        // 13:00–21:00 runs across 09:00–17:00 — the spec's own example.
        $afternoon = Shift::factory()->for($this->outlet)->between('13:00', '21:00')->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.show', ['shift' => $afternoon])
            ->set('employeeId', $baker->id)
            ->call('assign')
            ->assertHasErrors('employeeId');

        $this->assertSame(0, $afternoon->assignments()->count());
    }

    public function test_the_clash_names_the_shift_already_being_worked(): void
    {
        $baker = User::factory()->baker()->create(['name' => 'Rosa Baker']);

        $morning = Shift::factory()->for($this->outlet)->between('09:00', '17:00')->create(['name' => 'Morning bake']);
        ShiftAssignment::factory()->for($morning)->for($baker)->create();

        $afternoon = Shift::factory()->for($this->outlet)->between('13:00', '21:00')->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Rosa Baker is already on Morning bake');

        app(AssignShift::class)->handle($afternoon, $baker, User::factory()->administrator()->create());
    }

    public function test_back_to_back_shifts_are_not_a_clash(): void
    {
        $cashier = User::factory()->cashier()->create();

        $early = Shift::factory()->for($this->outlet)->between('09:00', '17:00')->create();
        ShiftAssignment::factory()->for($early)->for($cashier)->create();

        // A handover at 17:00 is not two people working at once.
        $late = Shift::factory()->for($this->outlet)->between('17:00', '21:00')->create();

        app(AssignShift::class)->handle($late, $cashier, User::factory()->administrator()->create());

        $this->assertSame(1, $late->assignments()->count());
    }

    public function test_a_clash_on_another_day_is_not_a_clash(): void
    {
        $baker = User::factory()->baker()->create();

        $today = Shift::factory()->for($this->outlet)->between('09:00', '17:00')->create();
        ShiftAssignment::factory()->for($today)->for($baker)->create();

        $tomorrow = Shift::factory()->for($this->outlet)
            ->on(now()->addDay()->toDateString(), '09:00', '17:00')
            ->create();

        app(AssignShift::class)->handle($tomorrow, $baker, User::factory()->administrator()->create());

        $this->assertSame(1, $tomorrow->assignments()->count());
    }

    public function test_assigning_the_same_employee_twice_leaves_one_place(): void
    {
        $shift = Shift::factory()->for($this->outlet)->create();
        $baker = User::factory()->baker()->create();
        $administrator = User::factory()->administrator()->create();

        $action = app(AssignShift::class);

        $action->handle($shift, $baker, $administrator);
        $action->handle($shift->fresh(), $baker, $administrator);

        $this->assertSame(1, $shift->assignments()->count());
    }

    public function test_an_administrator_takes_someone_off_a_shift(): void
    {
        $shift = Shift::factory()->for($this->outlet)->create();
        $assignment = ShiftAssignment::factory()->for($shift)->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.show', ['shift' => $shift])
            ->call('unassign', $assignment->id);

        $this->assertDatabaseMissing('shift_assignments', ['id' => $assignment->id]);
    }

    public function test_an_assignment_on_another_shift_is_never_removed(): void
    {
        $mine = Shift::factory()->for($this->outlet)->create();
        $theirs = Shift::factory()->for($this->outlet)->create();
        $theirAssignment = ShiftAssignment::factory()->for($theirs)->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.show', ['shift' => $mine])
            ->call('unassign', $theirAssignment->id);

        $this->assertDatabaseHas('shift_assignments', ['id' => $theirAssignment->id]);
    }

    public function test_the_roster_shows_the_week_and_flags_unstaffed_shifts(): void
    {
        $monday = now()->startOfWeek();

        $staffed = Shift::factory()->for($this->outlet)
            ->on($monday->toDateString(), '05:00', '13:00')
            ->create(['name' => 'Morning bake']);
        ShiftAssignment::factory()->for($staffed)->create();

        Shift::factory()->for($this->outlet)
            ->on($monday->addDays(2)->toDateString(), '09:00', '17:00')
            ->create(['name' => 'Counter cover']);

        // Next week's shift is not this week's business.
        Shift::factory()->for($this->outlet)
            ->on($monday->addWeek()->toDateString(), '09:00', '17:00')
            ->create(['name' => 'Next week']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.workforce.index')
            ->assertSet('unstaffedCount', 1)
            ->assertSee('Morning bake')
            ->assertSee('Counter cover')
            ->assertDontSee('Next week');
    }
}
