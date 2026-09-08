<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\Outlet;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyScheduleTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $outlet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outlet = Outlet::factory()->create(['name' => 'Mall Kiosk']);
    }

    /**
     * A shift running between two times relative to now, with the given people
     * on it.
     */
    private function shift(string $name, int $hoursFromNow, User ...$employees): Shift
    {
        $shift = Shift::factory()->for($this->outlet)->create([
            'name' => $name,
            'starts_at' => now()->addHours($hoursFromNow),
            'ends_at' => now()->addHours($hoursFromNow + 8),
        ]);

        foreach ($employees as $employee) {
            ShiftAssignment::factory()->for($shift)->for($employee)->create();
        }

        return $shift;
    }

    public function test_every_role_can_see_their_own_schedule(): void
    {
        // The capability matrix gates management screens, not an employee's own
        // roster: a Baker and a Cashier both need to know when they work.
        foreach (UserRole::cases() as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route('employee.schedule'))
                ->assertOk();
        }
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('employee.schedule'))->assertRedirect(route('login'));
    }

    public function test_it_shows_only_the_signed_in_employee_shifts(): void
    {
        $baker = User::factory()->baker()->create();
        $someoneElse = User::factory()->cashier()->create();

        $this->shift('Morning bake', 2, $baker);
        $this->shift('Counter cover', 3, $someoneElse);

        Livewire::actingAs($baker)
            ->test('pages::employee.schedule')
            ->assertSee('Morning bake')
            ->assertDontSee('Counter cover');
    }

    public function test_past_shifts_are_hidden_unless_asked_for(): void
    {
        $baker = User::factory()->baker()->create();

        $this->shift('Yesterday bake', -30, $baker);
        $this->shift('Tomorrow bake', 24, $baker);

        Livewire::actingAs($baker)
            ->test('pages::employee.schedule')
            ->assertSee('Tomorrow bake')
            ->assertDontSee('Yesterday bake')
            ->set('includePast', true)
            ->assertSee('Yesterday bake');
    }

    public function test_the_next_shift_is_the_soonest_one_still_to_come(): void
    {
        $baker = User::factory()->baker()->create();

        $this->shift('Later this week', 48, $baker);
        $soonest = $this->shift('Tomorrow bake', 20, $baker);

        $component = Livewire::actingAs($baker)->test('pages::employee.schedule');

        $this->assertSame($soonest->id, $component->instance()->next()->id);
    }

    public function test_an_employee_with_nothing_scheduled_is_told_so(): void
    {
        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.schedule')
            ->assertSee('Nothing scheduled');
    }
}
