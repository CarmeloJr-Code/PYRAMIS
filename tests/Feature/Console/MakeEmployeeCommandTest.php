<?php

namespace Tests\Feature\Console;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MakeEmployeeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_verified_employee_with_the_given_role(): void
    {
        $this->artisan('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'baker',
        ])
            ->expectsQuestion('Password', 'correct-horse-battery-staple')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-staple')
            ->assertSuccessful();

        $employee = User::firstWhere('email', 'ana@purpleyam.test');

        $this->assertNotNull($employee);
        $this->assertSame('Ana Reyes', $employee->name);
        $this->assertSame(UserRole::Baker, $employee->role);
        $this->assertNotNull($employee->email_verified_at);
    }

    public function test_it_stores_the_password_hashed(): void
    {
        $this->artisan('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'cashier',
        ])
            ->expectsQuestion('Password', 'correct-horse-battery-staple')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-staple')
            ->assertSuccessful();

        $employee = User::firstWhere('email', 'ana@purpleyam.test');

        $this->assertNotSame('correct-horse-battery-staple', $employee->password);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $employee->password));
    }

    public function test_it_prompts_for_details_not_passed_as_options(): void
    {
        $this->artisan('make:employee')
            ->expectsQuestion('Full name', 'Ana Reyes')
            ->expectsQuestion('Email address', 'ana@purpleyam.test')
            ->expectsQuestion('Role', 'administrator')
            ->expectsQuestion('Password', 'correct-horse-battery-staple')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-staple')
            ->assertSuccessful();

        $this->assertSame(
            UserRole::Administrator,
            User::firstWhere('email', 'ana@purpleyam.test')->role,
        );
    }

    public function test_it_rejects_an_email_already_in_use(): void
    {
        User::factory()->create(['email' => 'ana@purpleyam.test']);

        $this->artisan('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'baker',
        ])
            ->expectsQuestion('Password', 'correct-horse-battery-staple')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-staple')
            ->assertFailed();

        $this->assertSame(1, User::where('email', 'ana@purpleyam.test')->count());
    }

    public function test_it_rejects_a_role_outside_the_enum(): void
    {
        $this->artisan('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'customer',
        ])
            ->expectsQuestion('Password', 'correct-horse-battery-staple')
            ->expectsQuestion('Confirm password', 'correct-horse-battery-staple')
            ->assertFailed();

        $this->assertDatabaseEmpty('users');
    }

    public function test_it_rejects_a_mismatched_password_confirmation(): void
    {
        $this->artisan('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'baker',
        ])
            ->expectsQuestion('Password', 'correct-horse-battery-staple')
            ->expectsQuestion('Confirm password', 'something-else-entirely')
            ->assertFailed();

        $this->assertDatabaseEmpty('users');
    }
}
