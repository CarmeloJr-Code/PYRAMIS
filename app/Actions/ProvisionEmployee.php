<?php

namespace App\Actions;

use App\Concerns\PasswordValidationRules;
use App\Enums\UserRole;
use App\Models\User;
use RuntimeException;

/**
 * Creates an employee account for the workspace.
 *
 * PYRAMIS has no public registration — accounts are provisioned by an
 * Administrator — so this is the one place an employee comes into existence
 * from the workspace, alongside the make:employee command for a terminal.
 */
class ProvisionEmployee
{
    use PasswordValidationRules;

    /**
     * Create the employee and mint the password they will first sign in with.
     *
     * The password is returned rather than stored anywhere in clear: the screen
     * that asked for it shows it once, and nothing else ever sees it again.
     *
     * @return array{0: User, 1: string}
     *
     * @throws RuntimeException when no password meeting the configured rules
     *                          could be generated
     */
    public function handle(string $name, string $email, UserRole $role): array
    {
        $password = $this->generatePassword();

        if ($password === null) {
            throw new RuntimeException('Could not generate a password meeting the configured rules.');
        }

        $employee = new User([
            'name' => $name,
            'email' => $email,
            'role' => $role,
            'is_active' => true,
            'password' => $password,
        ]);

        // Same reasoning as make:employee — no mailer is configured in
        // production, so an unverified account would be trapped at the
        // `verified` gate with no way to receive the link. The Administrator
        // creating the account is the verification.
        $employee->email_verified_at = now();

        $employee->save();

        return [$employee, $password];
    }
}
