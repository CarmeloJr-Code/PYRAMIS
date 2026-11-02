<?php

namespace App\Concerns;

use App\Models\User;
use App\Rules\EmailAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The one set of rules for every email the app keeps — a customer's on the
 * storefront, an employee's in the workspace — so they are all held to the
 * same standard and stored the same way.
 */
trait EmailValidationRules
{
    /**
     * Get the validation rules for a required email address.
     *
     * @return array<int, ValidationRule|string>
     */
    protected function emailRules(): array
    {
        return ['required', 'string', 'max:255', new EmailAddress];
    }

    /**
     * Get the validation rules for an email address that may be left out.
     *
     * @return array<int, ValidationRule|string>
     */
    protected function optionalEmailRules(): array
    {
        return ['nullable', 'string', 'max:255', new EmailAddress];
    }

    /**
     * Get the validation rules for an employee's sign-in email, which no other
     * account may already hold.
     *
     * @return array<int, Unique|ValidationRule|string>
     */
    protected function employeeEmailRules(?int $ignoreUserId = null): array
    {
        return [...$this->emailRules(), Rule::unique(User::class, 'email')->ignore($ignoreUserId)];
    }

    /**
     * The form an email is checked and stored in: no surrounding spaces, all
     * lowercase.
     *
     * Fortify lowercases the email at sign-in and password reset, so a stored
     * capital would leave the account unreachable, and a unique index that
     * tells Ana@ from ana@ would let one person hold two accounts.
     */
    protected function normaliseEmail(string $email): string
    {
        return Str::lower(trim($email));
    }
}
