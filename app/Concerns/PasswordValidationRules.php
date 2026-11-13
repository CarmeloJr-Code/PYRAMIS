<?php

namespace App\Concerns;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * A password may not contain any whitespace: a stray space typed at
     * sign-in would otherwise make a correct password fail.
     *
     * @return array<int, Closure|Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return [
            'required',
            'string',
            Password::default(),
            function (string $attribute, mixed $value, Closure $fail): void {
                if (is_string($value) && preg_match('/\s/u', $value) === 1) {
                    $fail(__('The :attribute may not contain spaces.'));
                }
            },
            'confirmed',
        ];
    }

    /**
     * Drop every whitespace character from a password being checked.
     *
     * No password can contain whitespace, so any that arrives with one being
     * entered is a slip of the keyboard or the clipboard, never part of it.
     */
    protected function withoutWhitespace(?string $password): string
    {
        return (string) preg_replace('/\s+/u', '', (string) $password);
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
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
