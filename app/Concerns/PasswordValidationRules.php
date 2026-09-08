<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, Password|ValidationRule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
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
