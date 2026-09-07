<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('employee.dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_every_employee_role_can_visit_the_dashboard(): void
    {
        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get(route('employee.dashboard'))
                ->assertOk()
                ->assertSee($role->label());
        }
    }

    public function test_the_dashboard_only_links_to_sections_the_role_may_open(): void
    {
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.dashboard'))
            ->assertSee(route('employee.sales'))
            ->assertDontSee(route('employee.production'))
            ->assertDontSee(route('employee.workforce'));
    }
}
