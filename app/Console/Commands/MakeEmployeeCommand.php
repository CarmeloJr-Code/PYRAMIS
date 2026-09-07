<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class MakeEmployeeCommand extends Command
{
    use PasswordValidationRules;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:employee
        {--name= : The employee\'s full name}
        {--email= : The employee\'s email address}
        {--role= : administrator, baker, or cashier}
        {--generate-password : Mint a strong random password and print it once instead of prompting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create an employee account for the PYRAMIS workspace';

    public function handle(): int
    {
        $generate = (bool) $this->option('generate-password');

        $needsPrompt = ! $generate
            || $this->option('name') === null
            || $this->option('email') === null
            || $this->option('role') === null;

        if ($needsPrompt && ! $this->input->isInteractive()) {
            $this->components->error(
                'This command needs an interactive terminal, unless --name, --email, --role and --generate-password are all given.',
            );

            return self::FAILURE;
        }

        $name = $this->option('name') ?? text(
            label: 'Full name',
            required: true,
        );

        $email = $this->option('email') ?? text(
            label: 'Email address',
            required: true,
        );

        $role = $this->option('role') ?? select(
            label: 'Role',
            options: $this->roleOptions(),
        );

        if ($generate) {
            $password = $this->generatePassword();

            if ($password === null) {
                $this->components->error('Could not generate a password meeting the configured rules.');

                return self::FAILURE;
            }

            $confirmation = $password;
        } else {
            $password = password(label: 'Password');
            $confirmation = password(label: 'Confirm password');
        }

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
            'role' => ['required', Rule::enum(UserRole::class)],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $employee = new User([
            'name' => $name,
            'email' => $email,
            'role' => UserRole::from($role),
            'password' => $password,
        ]);

        // PYRAMIS provisions employees rather than letting them register, and no
        // mailer is configured in production — an unverified account would be
        // trapped at the `verified` gate with no way to receive the link. The
        // administrator running this command is the verification.
        $employee->email_verified_at = now();

        $employee->save();

        $this->components->info("Employee [{$employee->email}] created as {$employee->role->label()}.");

        if ($generate) {
            // Printed once and never stored in clear. Whoever runs this is
            // responsible for the terminal it lands in — a scheduled job would
            // put it in the service logs.
            $this->newLine();
            $this->components->warn('Generated password — copy it now, it will not be shown again:');
            $this->line("  {$password}");
            $this->newLine();
            $this->components->warn('Change it after the first sign-in.');
        }

        return self::SUCCESS;
    }

    /**
     * Mint a random password that satisfies the configured password rules.
     *
     * Str::password draws from a shuffled pool, so a given draw is not
     * guaranteed to contain every required character class. Validate each
     * candidate against the same rules a human would face rather than assume.
     */
    protected function generatePassword(): ?string
    {
        foreach (range(1, 10) as $ignored) {
            $candidate = Str::password(24);

            $validator = Validator::make(
                ['password' => $candidate, 'password_confirmation' => $candidate],
                ['password' => $this->passwordRules()],
            );

            if ($validator->passes()) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The selectable roles, labelled with both vocabularies.
     *
     * @return array<string, string>
     */
    protected function roleOptions(): array
    {
        $options = [];

        foreach (UserRole::cases() as $role) {
            $options[$role->value] = "{$role->name} ({$role->label()})";
        }

        return $options;
    }
}
