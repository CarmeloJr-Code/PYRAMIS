<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A Philippine mobile number as eleven digits and nothing else: 09XXXXXXXXX,
 * the one way it is typed and stored, so every number reads alike.
 */
class PhilippineMobile implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^09\d{9}$/', $value) !== 1) {
            $fail(__('Please enter an 11-digit mobile number, like 09171234567.'));
        }
    }
}
