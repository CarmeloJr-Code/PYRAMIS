<?php

namespace Tests\Feature\Console;

use App\Console\Commands\MakeEmployeeCommand;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

    public function test_generate_password_creates_the_employee_without_prompting(): void
    {
        $this->artisan('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'administrator',
            '--generate-password' => true,
        ])->assertSuccessful();

        $employee = User::firstWhere('email', 'ana@purpleyam.test');

        $this->assertNotNull($employee);
        $this->assertSame(UserRole::Administrator, $employee->role);
        $this->assertNotNull($employee->email_verified_at);
    }

    public function test_the_printed_generated_password_actually_works(): void
    {
        // Artisan::call, not $this->artisan(), so the printed output is capturable
        // — and so this exercises the genuinely non-interactive path.
        $exitCode = Artisan::call('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'baker',
            '--generate-password' => true,
        ]);

        $this->assertSame(0, $exitCode);

        // Whatever was printed, not a 24-character token: a password mangled on
        // the way out is shorter, and matching loosely here means the failure
        // reads as "printed something that does not work" rather than as
        // "printed nothing".
        preg_match('/^\s+(\S+)\s*$/m', Artisan::output(), $matches);

        $this->assertNotEmpty($matches, 'The generated password was not printed.');

        $this->assertTrue(
            Hash::check($matches[1], User::firstWhere('email', 'ana@purpleyam.test')->password),
        );
    }

    public function test_a_password_carrying_console_markup_prints_as_it_was_stored(): void
    {
        // Str::password draws "<" and ">" from its symbol pool, and the console
        // formatter reads "<...>" as a style tag and swallows it. A password
        // could reach the terminal shorter than the one stored — about one draw
        // in a hundred and fifty. Nothing about the account looked wrong; the
        // new employee simply could not sign in.
        //
        // The generator is random and the command takes no password to hand it,
        // so rather than draw until the shape that breaks turns up, this stands
        // a command in front of it whose draw is fixed to exactly that shape.
        Artisan::registerCommand(new MarkupPasswordCommand);

        Artisan::call('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'baker',
            '--generate-password' => true,
        ]);

        $this->assertStringContainsString(
            MarkupPasswordCommand::PASSWORD,
            Artisan::output(),
            'The password reached the terminal with its markup eaten.',
        );

        $this->assertTrue(
            Hash::check(
                MarkupPasswordCommand::PASSWORD,
                User::firstWhere('email', 'ana@purpleyam.test')->password,
            ),
        );
    }

    public function test_it_refuses_a_non_interactive_run_without_generate_password(): void
    {
        $this->artisan('make:employee', [
            '--name' => 'Ana Reyes',
            '--email' => 'ana@purpleyam.test',
            '--role' => 'baker',
            '--no-interaction' => true,
        ])->assertFailed();

        $this->assertDatabaseEmpty('users');
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

/**
 * make:employee with its random draw replaced by a fixed one.
 *
 * The password is the shape that broke: sequences the console formatter reads
 * as style tags, including a real one it would happily consume. Registering
 * this takes the command's name, so the test drives the ordinary code path and
 * only the draw is decided.
 */
class MarkupPasswordCommand extends MakeEmployeeCommand
{
    public const PASSWORD = 'aa<info>bb<x/>cc<>dd1234';

    protected function generatePassword(): ?string
    {
        return self::PASSWORD;
    }
}
